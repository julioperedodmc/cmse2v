<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\RfidTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Http;
use App\Models\Product;

class AuthController extends Controller
{
    public function validateField(Request $request)
    {
        $field = $request->input('field');
        $value = $request->input('value');

        if ($field === 'billing_document') {
            $exists = User::where('billing_document', $value)->exists();
            if ($exists) {
                return response()->json(['message' => 'Este NIT/CI ya está registrado.', 'available' => false], 422);
            }
        }

        if ($field === 'email') {
            $exists = User::where('email', $value)->exists();
            if ($exists) {
                return response()->json(['message' => 'Este correo ya está registrado.', 'available' => false], 422);
            }
        }

        return response()->json(['available' => true]);
    }

    public function register(Request $request)
    {
        // Trim email to handle accidental spaces (e.g. "user@ gmail.com" or "user@gmail.com ")
        if ($request->has('email')) {
            $request->merge([
                'email' => str_replace(' ', '', $request->email)
            ]);
        }

        try {
            $request->validate([
                'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]+$/u'],
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8',
                'nfc_id' => 'nullable|string|max:255',
                'billing_document' => 'required|numeric|digits_between:5,15|unique:users',
                'billing_razon_social' => 'sometimes|nullable|string|max:255',
                'billing_doc_type' => 'sometimes|nullable|in:CI,NIT',
                'phone' => 'sometimes|nullable|string|max:30',
            ], [
                'name.required' => 'El nombre es obligatorio.',
                'name.regex' => 'El nombre solo debe contener letras y espacios.',
                'email.required' => 'El correo electrónico es obligatorio.',
                'email.email' => 'Ingresa un formato de correo válido (ej: usuario@correo.com).',
                'email.unique' => 'Este correo ya está registrado en el sistema.',
                'password.required' => 'La contraseña es obligatoria.',
                'password.min' => 'La contraseña es muy corta, debe tener al menos 8 caracteres.',
                'billing_document.numeric' => 'El NIT/CI debe contener únicamente números.',
                'billing_document.digits_between' => 'El NIT/CI debe tener entre 5 y 15 dígitos.',
                'billing_document.unique' => 'Este NIT/CI ya está registrado por otro usuario.',
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $response = [
                'message' => $e->getMessage(),
                'errors' => $errors,
            ];

            if (isset($errors['billing_document'])) {
                $response['error_code'] = 'DUPLICATE_NIT';
                $response['message'] = 'El NIT/CI ya se encuentra registrado.';
            }

            return response()->json($response, 422);
        }

        $result = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'billing_document' => $request->billing_document,
                'billing_razon_social' => $request->billing_razon_social,
                'billing_doc_type' => $request->billing_doc_type ?: 'NIT',
                'phone' => $request->phone,
            ]);

            // Ensure mobile default role exists and assign it
            $role = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
            $user->assignRole($role);

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'currency' => 'BOB', 'is_postpaid' => false]
            );

            $rawTag = $request->input('nfc_id');
            if ($rawTag) {
                $tagCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawTag));
            } else {
                $tagCode = 'A' . strtoupper(Str::random(7));
            }

            // Guarantee uniqueness
            while (RfidTag::where('tag_code', $tagCode)->exists()) {
                $tagCode = 'A' . strtoupper(Str::random(7));
            }

            $virtualProduct = Product::where('internal_code', 'VIRTUAL-TAG')->first();

            $tag = RfidTag::create([
                'tag_code' => $tagCode,
                'user_id' => $user->id,
                'product_id' => $virtualProduct?->id,
                'name' => 'Tag Virtual App',
                'is_active' => true,
                'is_virtual' => true,
            ]);

            $token = $user->createToken('mobile-app')->plainTextToken;

            return compact('user', 'wallet', 'tag', 'token');
        });

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => $result['user'],
            'wallet' => $result['wallet'],
            'rfid_tag' => $result['tag'],
            'token' => $result['token'],
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales son incorrectas.'],
            ]);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function googleLogin(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $idToken = $request->id_token;

        // Verify with Google
        $response = Http::get("https://oauth2.googleapis.com/tokeninfo?id_token={$idToken}");

        if ($response->failed()) {
            return response()->json(['message' => 'Token de Google inválido'], 401);
        }

        $payload = $response->json();

        // Optional: Verify audience (should match the Web Client ID configured in Firebase)
        // $clientId = config('services.google.client_id');
        // if ($payload['aud'] !== $clientId) { return response()->json(['message' => 'Unauthorized Client'], 401); }

        $email = $payload['email'];
        $name = $payload['name'] ?? 'Google User';
        $googleId = $payload['sub'];

        $user = User::where('email', $email)->first();

        if (!$user) {
            // Register new user
            $user = DB::transaction(function () use ($email, $name) {
                $u = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(16)), // Random password for social login
                ]);

                $role = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
                $u->assignRole($role);

                Wallet::firstOrCreate(
                    ['user_id' => $u->id],
                    ['balance' => 0, 'currency' => 'BOB', 'is_postpaid' => false]
                );

                $tagCode = 'A' . strtoupper(Str::random(7));
                while (RfidTag::where('tag_code', $tagCode)->exists()) {
                    $tagCode = 'A' . strtoupper(Str::random(7));
                }

                $virtualProduct = Product::where('internal_code', 'VIRTUAL-TAG')->first();

                RfidTag::create([
                    'tag_code' => $tagCode,
                    'user_id' => $u->id,
                    'product_id' => $virtualProduct?->id,
                    'name' => 'Tag Virtual App',
                    'is_active' => true,
                    'is_virtual' => true,
                ]);

                return $u;
            });
        }

        $token = $user->createToken('mobile-app')->plainTextToken;
        $wallet = Wallet::where('user_id', $user->id)->first();
        $tag = RfidTag::where('user_id', $user->id)->orderByDesc('is_virtual')->first();

        return response()->json([
            'message' => 'Inicio de sesión exitoso',
            'user' => $user,
            'wallet' => $wallet,
            'rfid_tag' => $tag,
            'token' => $token,
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $wallet = Wallet::where('user_id', $user->id)->first();
        $tag = RfidTag::where('user_id', $user->id)->orderByDesc('is_virtual')->first();

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'wallet' => $wallet,
            'rfid_tag' => $tag,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]+$/u'],
            'billing_document' => [
                'sometimes',
                'required',
                'numeric',
                'digits_between:5,15',
                'unique:users,billing_document,' . $user->id
            ],
            'billing_razon_social' => 'sometimes|nullable|string|max:255',
            'billing_doc_type' => 'sometimes|nullable|in:CI,NIT,OTHER',
            'phone' => 'sometimes|nullable|string|max:30',
            'password' => 'sometimes|nullable|filled|string|min:8|confirmed',
        ], [
            'name.regex' => 'El nombre solo debe contener letras y espacios.',
            'billing_document.numeric' => 'El NIT/CI debe contener únicamente números.',
            'billing_document.digits_between' => 'El NIT/CI debe tener entre 5 y 15 dígitos.',
            'billing_document.unique' => 'Este NIT/CI ya está registrado por otro usuario.',
            'billing_doc_type.in' => 'El tipo de documento debe ser CI o NIT.',
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ]);

        if ($request->has('name'))
            $user->name = $request->name;
        if ($request->has('phone'))
            $user->phone = $request->phone;
        if ($request->has('billing_document'))
            $user->billing_document = $request->billing_document;
        if ($request->has('billing_razon_social'))
            $user->billing_razon_social = $request->billing_razon_social;
        if ($request->has('billing_doc_type'))
            $user->billing_doc_type = $request->billing_doc_type;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            'message' => 'Perfil actualizado exitosamente',
            'user' => $user->fresh(),
        ]);
    }

    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $user = $request->user();
        $user->fcm_token = $request->fcm_token;
        $user->save();

        return response()->json([
            'message' => 'Token FCM actualizado exitosamente',
        ]);
    }

    public function sendResetPin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un formato de correo válido.',
        ]);

        $user = User::where('email', $request->email)->first();

        // Security: Don't leak if the user exists, but send the success message.
        if (!$user) {
            return response()->json([
                'message' => 'Si el correo electrónico está registrado, recibirás un PIN de verificación en unos instantes.',
                'status' => 'sent',
            ]);
        }

        // Generate 6-digit numeric PIN
        $pin = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Store token in password_reset_tokens
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => Hash::make($pin),
                'created_at' => now(),
            ]
        );

        // Send Notification
        try {
            $user->notify(new \App\Notifications\ResetPasswordPIN($pin));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error sending reset password notification: ' . $e->getMessage());
            return response()->json([
                'message' => 'No se pudo enviar el correo de recuperación. Por favor intenta más tarde.',
                'status' => 'error',
            ], 500);
        }

        return response()->json([
            'message' => 'Si el correo electrónico está registrado, recibirás un PIN de verificación en unos instantes.',
            'status' => 'sent',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Ingresa un formato de correo válido.',
            'code.required' => 'El código de verificación es obligatorio.',
            'code.size' => 'El código de verificación debe tener 6 dígitos.',
            'password.required' => 'La nueva contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record) {
            return response()->json([
                'message' => 'El código de verificación es inválido o ha expirado.',
                'errors' => ['code' => ['El código es inválido.']]
            ], 422);
        }

        // Check expiration (15 minutes limit)
        $createdAt = \Carbon\Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'message' => 'El código de verificación ha expirado.',
                'errors' => ['code' => ['El código ha expirado.']]
            ], 422);
        }

        // Verify PIN hash
        if (!Hash::check($request->code, $record->token)) {
            return response()->json([
                'message' => 'El código de verificación es incorrecto.',
                'errors' => ['code' => ['El código ingresado es incorrecto.']]
            ], 422);
        }

        // Update user password
        $user = User::where('email', $request->email)->first();
        if ($user) {
            $user->password = Hash::make($request->password);
            $user->save();
        }

        // Clean up the token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Contraseña restablecida de forma exitosa.',
            'status' => 'success',
        ]);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // Execute safety deletion (all cascading logic and anonymization is handled in User's static booted deleting hook!)
        DB::transaction(function () use ($user) {
            $user->delete();
        });

        return response()->json([
            'message' => 'Tu cuenta y datos personales han sido eliminados de forma exitosa de acuerdo con las normativas GDPR.',
            'status' => 'success',
        ]);
    }
}

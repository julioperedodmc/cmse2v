<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\RfidTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\LibelulaPaymentService;
use Barryvdh\DomPDF\Facade\Pdf;

class WalletController extends Controller
{
    public function downloadHistory(Request $request)
    {
        $user = $request->user();

        // Manual auth for direct link downloads if sanctum fails
        if (!$user && $request->has('token')) {
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        $wallet = Wallet::where('user_id', $user->id)->first();

        if (!$wallet) {
            return response()->json(['message' => 'Sin billetera activa'], 404);
        }

        $transactions = $wallet->transactions()->latest()->get();

        $pdf = Pdf::loadView('pdf.wallet_history', [
            'user' => $user,
            'transactions' => $transactions,
        ]);

        return $pdf->download("Historial_ElectroPoint_{$user->id}.pdf");
    }

    public function balance(Request $request)
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['balance' => 0, 'currency' => 'BOB', 'is_postpaid' => false, 'credit_limit' => 0]
        );

        // AUTO-VERIFY PENDING LIBELULA TRANSACTIONS
        $pendingTxs = DB::table('wallet_transactions')
            ->where('wallet_id', $wallet->id)
            ->where('status', 'PENDING')
            ->where('payment_method', 'LIBELULA')
            ->get();

        if ($pendingTxs->isNotEmpty()) {
            $libelula = app(\App\Services\LibelulaPaymentService::class);
            foreach ($pendingTxs as $tx) {
                $libelula->verifyStatus((int) $tx->id);
            }
            $wallet->refresh();
        }

        $tags = RfidTag::where('user_id', $request->user()->id)
            ->select('id', 'tag_code', 'name', 'balance', 'currency', 'is_active', 'is_virtual', 'user_id')
            ->get();

        $appBalance = (float) $wallet->balance;
        $physicalBalance = (float) $tags->where('is_virtual', false)->sum('balance');

        return response()->json([
            'balance' => $appBalance + $physicalBalance,
            'app_balance' => $appBalance,
            'physical_balance' => $physicalBalance,
            'currency' => $wallet->currency,
            'credit_limit' => (float) $wallet->credit_limit,
            'tags' => $tags
        ]);
    }

    public function updateTag(Request $request, RfidTag $tag)
    {
        if ($tag->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($tag->is_virtual) {
            return response()->json(['message' => 'No se puede cambiar el nombre de un Tag Virtual'], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $tag->update(['name' => $validated['name']]);

        return response()->json(['message' => 'Tarjeta actualizada exitosamente', 'tag' => $tag]);
    }

    public function history(Request $request)
    {
        $wallet = Wallet::where('user_id', $request->user()->id)->first();

        if (!$wallet) {
            return response()->json([
                'data' => [],
                'total' => 0,
            ]);
        }

        $transactions = $wallet->transactions()->latest()->paginate(50);

        return response()->json($transactions);
    }

    public function topup(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1|max:10000',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        if (empty($user->billing_document)) {
            return response()->json([
                'message' => 'Se requiere registrar un NIT/CI en su perfil para realizar recargas.',
                'status' => 'billing_document_required',
            ], 422);
        }

        $amount = round((float) $request->input('amount'), 2);

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'currency' => 'BOB', 'is_postpaid' => false, 'credit_limit' => 0]
        );

        DB::transaction(function () use ($wallet, $user, $amount, $request) {
            $wallet->balance = round(((float) $wallet->balance) + $amount, 2);
            $wallet->save();

            $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';
            $statusCol = Schema::hasColumn('wallet_transactions', 'status') ? 'status' : null;

            $insert = [
                'wallet_id' => $wallet->id,
                'type' => 'RECHARGE',
                'amount' => $amount,
                $refCol => $request->input('reference') ?: ('APP-RECHARGE-' . now()->format('YmdHis')),
                'description' => $request->input('description', 'Recarga Wallet'),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('wallet_transactions', 'user_id')) {
                $insert['user_id'] = $user->id;
            }
            if (Schema::hasColumn('wallet_transactions', 'balance_after')) {
                $insert['balance_after'] = $wallet->balance;
            }
            if (Schema::hasColumn('wallet_transactions', 'currency')) {
                $insert['currency'] = $wallet->currency ?? 'BOB';
            }
            if ($statusCol) {
                $insert[$statusCol] = 'COMPLETED';
            }

            DB::table('wallet_transactions')->insert($insert);
        });

        // Notify the user
        $user->notify(new \App\Notifications\GeneralNotification(
            'Recarga exitosa',
            "Se ha realizado una recarga de Bs " . number_format($amount, 2) . " en tu billetera.",
            ['type' => 'RECHARGE', 'amount' => $amount]
        ));

        return response()->json([
            'message' => 'Top-up aplicado correctamente',
            'wallet' => [
                'balance' => $wallet->fresh()->balance,
                'currency' => $wallet->currency,
                'credit_limit' => $wallet->credit_limit,
            ],
        ]);
    }

    public function libelulaCheckout(Request $request, LibelulaPaymentService $libelula)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1|max:10000',
            'description' => 'nullable|string|max:255',
            'razon_social' => 'nullable|string|max:255',
            'documento' => 'nullable|string|max:50',
            'complemento' => 'nullable|string|max:50',
            'doc_type' => 'nullable|in:CI,NIT',
        ], [
            'amount.min' => 'El monto mínimo para recarga con Libélula es Bs 1.00',
        ]);

        $user = $request->user();

        $documento = $request->input('documento') ?: $user->billing_document;
        if (empty($documento)) {
            return response()->json([
                'message' => 'Se requiere registrar un NIT/CI en su perfil para realizar recargas.',
                'status' => 'billing_document_required',
            ], 422);
        }

        $docType = $request->input('doc_type') ?: $user->billing_doc_type;
        $complemento = ($docType === 'NIT') ? '' : ($request->input('complemento') ?? $user->billing_complement);

        if ($request->filled('documento') || $request->filled('complemento') || $request->filled('razon_social') || $request->filled('doc_type')) {
            $user->billing_document = $request->input('documento') ?: $user->billing_document;
            $user->billing_doc_type = $docType;
            $user->billing_complement = $complemento;
            $user->billing_razon_social = $request->input('razon_social') ?: $user->billing_razon_social;
            $user->save();
        }

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'currency' => 'BOB', 'is_postpaid' => false, 'credit_limit' => 0]
        );

        \Illuminate\Support\Facades\Log::error('DEBUG: LIBELULA START', ['amount' => $request->input('amount'), 'user_id' => $user->id]);

        $settings = \App\Models\SystemSetting::get();
        $rechargeProduct = $settings->product_recharge_id ? \App\Models\Product::find($settings->product_recharge_id) : null;
        $defaultDescription = $rechargeProduct?->name ?? 'Recarga de Saldo';

        $result = $libelula->createPayment(
            $wallet,
            round((float) $request->input('amount'), 2),
            $request->input('description') ?: $defaultDescription,
            [
                'razon_social' => $request->input('razon_social') ?: $user->billing_razon_social,
                'documento' => $request->input('documento') ?: $user->billing_document,
                'complemento' => $complemento,
                'doc_type' => $docType,
            ]
        );

        if (!($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'No se pudo crear el pago',
                'detail' => $result['detail'] ?? null,
            ], 422);
        }

        return response()->json([
            'message' => 'Pago Libélula creado',
            'transaction_id' => $result['transaction_id'] ?? null,
            'payment_url' => $result['payment_url'] ?? null,
            'qr_image' => $result['qr_image'] ?? null,
        ]);
    }

    public function libelulaStatus(Request $request, int $transactionId)
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['balance' => 0, 'currency' => 'BOB', 'is_postpaid' => false, 'credit_limit' => 0]
        );

        $tx = DB::table('wallet_transactions')
            ->where('id', $transactionId)
            ->where('wallet_id', $wallet->id)
            ->first();

        if (!$tx) {
            return response()->json([
                'message' => 'Transacción no encontrada',
            ], 404);
        }

        $statusCol = Schema::hasColumn('wallet_transactions', 'status') ? 'status' : null;
        $status = $statusCol ? (string) ($tx->{$statusCol} ?? 'PENDING') : 'PENDING';

        if (strtoupper($status) === 'PENDING') {
            $libelula = app(\App\Services\LibelulaPaymentService::class);
            if ($libelula->verifyStatus($transactionId)) {
                $tx = DB::table('wallet_transactions')->where('id', $transactionId)->first();
                $status = $statusCol ? (string) ($tx->{$statusCol} ?? 'COMPLETED') : 'COMPLETED';
            }
        }

        return response()->json([
            'transaction_id' => (int) $tx->id,
            'status' => strtoupper($status),
            'amount' => (float) ($tx->amount ?? 0),
            'wallet_balance' => (float) ($wallet->fresh()->balance ?? 0),
            'currency' => $wallet->currency ?? 'BOB',
        ]);
    }

    public function deletePending(Request $request, int $transactionId)
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['balance' => 0, 'currency' => 'BOB', 'is_postpaid' => false, 'credit_limit' => 0]
        );

        $tx = DB::table('wallet_transactions')
            ->where('id', $transactionId)
            ->where('wallet_id', $wallet->id)
            ->first();

        if (!$tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        $status = strtoupper((string) ($tx->status ?? 'PENDING'));
        if (!in_array($status, ['PENDING', 'PROCESSING', '-'], true)) {
            return response()->json(['message' => 'Solo se pueden eliminar pendientes'], 422);
        }

        DB::table('wallet_transactions')->where('id', $transactionId)->delete();

        return response()->json(['message' => 'Pendiente eliminado']);
    }

    public function downloadInvoice(Request $request, int $transactionId)
    {
        $user = $request->user();

        // Manual auth for direct link downloads if sanctum fails
        if (!$user && $request->has('token')) {
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        $wallet = Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            return response()->json(['message' => 'Billetera no encontrada'], 404);
        }

        $tx = \App\Models\WalletTransaction::where('id', $transactionId)
            ->where('wallet_id', $wallet->id)
            ->first();

        if (!$tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        $pdf = Pdf::loadView('pdf.wallet_history', [
            'user' => $user,
            'transactions' => [$tx], // Just this one
            'is_single' => true,
        ]);

        return $pdf->download("Recibo_Pago_{$tx->id}.pdf");
    }
}

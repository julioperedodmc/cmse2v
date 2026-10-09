<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class ExternalClientController extends Controller
{
    /**
     * Devuelve únicamente Nombre, Email, NIT/CI, Teléfono y Placas asociadas.
     * Diseñado para consumo seguro desde aplicaciones externas (React, etc).
     */
    public function index(Request $request)
    {
        $query = User::query()
            ->with(['vehicles:id,user_id,plate'])
            ->where('is_admin', 0)
            ->whereDoesntHave('roles', function ($q) {
                $q->where('name', '<>', 'client');
            })
            ->where('name', 'NOT LIKE', 'Usuario RFID%')
            ->where('email', 'NOT LIKE', '%@evce.temp');

        // Búsqueda opcional por nombre, email, nit/ci, teléfono o placa
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('billing_document', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('vehicles', function ($vq) use ($search) {
                        $vq->where('plate', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min((int) $request->query('per_page', 50), 100);
        $users = $query->paginate($perPage);

        $data = collect($users->items())->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'nit_ci' => $user->billing_document,
                'phone' => $user->phone,
                'plates' => $user->vehicles->pluck('plate')->filter()->values()->toArray(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ]
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\RfidTag;
use Illuminate\Http\Request;

class RfidTagController extends Controller
{
    public function lookup(Request $request)
    {
        $tagCode = $request->query('tag_code');

        if (empty($tagCode)) {
            return response()->json([
                'success' => false,
                'message' => 'El código de la tarjeta (tag_code) es requerido.'
            ], 400);
        }

        // Limpiar el código: pasar a mayúsculas y remover todo lo que no sea alfanumérico
        $cleanTagCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tagCode));

        // 1. Buscar coincidencia exacta del código limpio o el original
        $tag = RfidTag::with('user')
            ->where('is_virtual', false)
            ->where(function ($query) use ($tagCode, $cleanTagCode) {
                $query->where('tag_code', $tagCode)
                      ->orWhere('tag_code', $cleanTagCode);
            })
            ->first();

        // 2. Si no encuentra coincidencia exacta, hacer búsqueda similar (coincidencia parcial al final)
        if (!$tag && strlen($cleanTagCode) >= 4) {
            $tag = RfidTag::with('user')
                ->where('is_virtual', false)
                ->where('tag_code', 'LIKE', '%' . $cleanTagCode)
                ->first();
        }

        if (!$tag) {
            return response()->json([
                'success' => false,
                'message' => 'Tarjeta RFID física no encontrada.'
            ], 404);
        }

        $user = $tag->user;

        // 1. Obtener resumen de uso de la tarjeta
        $totalCargas = (int) \App\Models\ChargingSession::where('rfid_tag_id', $tag->id)->count();
        $totalKwh = (float) \App\Models\ChargingSession::where('rfid_tag_id', $tag->id)->sum('total_energy_kwh');
        $totalGastado = (float) \App\Models\ChargingSession::where('rfid_tag_id', $tag->id)->sum('total_cost');

        $resumenUso = [
            'total_cargas' => $totalCargas,
            'total_kwh' => round($totalKwh, 2),
            'total_gastado' => round($totalGastado, 2),
        ];

        // 2. Obtener todas las cargas (sesiones) de esta tarjeta
        $cargas = \App\Models\ChargingSession::with('station')
            ->where('rfid_tag_id', $tag->id)
            ->orderBy('start_time', 'desc')
            ->get()
            ->map(function ($session) {
                return [
                    'id' => $session->id,
                    'transaction_id' => $session->transaction_id,
                    'estacion' => $session->station ? $session->station->name : 'Estación Desconocida',
                    'conector_id' => $session->connector_id,
                    'fecha_inicio' => $session->start_time ? $session->start_time->setTimezone('America/La_Paz')->toDateTimeString() : null,
                    'fecha_fin' => $session->stop_time ? $session->stop_time->setTimezone('America/La_Paz')->toDateTimeString() : null,
                    'kwh' => (float) $session->total_energy_kwh,
                    'costo' => (float) $session->total_cost,
                    'estado' => $session->status,
                    'moneda' => 'BOB',
                ];
            });

        /*
        // 3. Obtener todas las transacciones del monedero del usuario (Comentado temporalmente)
        $transacciones = collect();
        if ($tag->user_id) {
            $transacciones = \App\Models\WalletTransaction::where('user_id', $tag->user_id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'tipo' => $tx->type, // RECHARGE, CHARGE, REFUND
                        'monto' => (float) $tx->amount,
                        'saldo_despues' => (float) $tx->balance_after,
                        'estado' => $tx->status,
                        'fecha' => $tx->created_at ? $tx->created_at->setTimezone('America/La_Paz')->toDateTimeString() : null,
                        'descripcion' => $tx->description,
                    ];
                });
        }
        */

        return response()->json([
            'success' => true,
            'id' => $tag->id,
            'codigo' => $tag->tag_code,
            'saldo' => (float) $tag->balance,
            'moneda' => $tag->currency ?? 'BOB',
            'activo' => (bool) $tag->is_active,
            'nombre_tarjeta' => $tag->name,
            'usuario' => [
                'id' => $user ? $user->id : null,
                'nombre' => $user ? $user->name : 'Sin usuario asignado',
                'email' => $user ? $user->email : null,
            ],
            'resumen_uso' => $resumenUso,
            'cargas' => $cargas,
            // 'transacciones' => $transacciones, // Comentado temporalmente
        ]);
    }
}

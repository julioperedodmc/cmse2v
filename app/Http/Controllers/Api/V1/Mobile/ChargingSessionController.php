<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\RfidTag;
use App\Models\Station;
use App\Services\SteveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

class ChargingSessionController extends Controller
{
    public function index(Request $request)
    {
        $sessions = ChargingSession::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Enhance active sessions with real-time metrics from SteVe
        $sessions->getCollection()->transform(function ($session) {
            if ($session->status === 'Active' && $session->transaction_id) {
                $metrics = $this->getSteveLiveMetrics($session);
                $session->current_metrics = $metrics;
                
                // CRITICAL: Overwrite top-level fields for the App's UI
                $session->total_cost = $metrics['total_cost'];
                $session->total_energy_kwh = $metrics['energy_kwh'];
                $session->current_soc = $metrics['soc'];
                $session->power_kw = $metrics['power_kw']; // Add this!
            }
            
            return $session;
        });

        return response()->json($sessions);
    }

    public function show(Request $request, ChargingSession $session)
    {
        if ($session->user_id !== $request->user()->id && !$request->user()->is_admin) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Include current metrics if active
        if ($session->status === 'Active' && $session->transaction_id) {
            $session->current_metrics = $this->getSteveLiveMetrics($session);
        }

        return response()->json($session);
    }

    /**
     * Fetches latest metrics (Power, Energy, SoC) from SteVe meter_value table.
     */
    private function getSteveLiveMetrics(ChargingSession $session): array
    {
        $transactionId = (int) $session->transaction_id;
        try {
            $latestValues = DB::connection('steve')
                ->table('connector_meter_value')
                ->where('transaction_pk', $transactionId)
                ->orderByDesc('value_timestamp')
                ->get();

            $metrics = [
                'power_kw' => 0.0,
                'energy_kwh' => round((float) $session->total_energy_kwh, 2),
                'soc' => $session->current_soc,
                'total_cost' => round((float) $session->total_cost, 2),
                'timestamp' => now()->toIso8601String(),
            ];

            if ($latestValues->isEmpty()) {
                return $metrics;
            }

            // 1. Get meter_start for this transaction to subtract it from current values
            // Try different tables if 'transaction' doesn't return value
            $txStart = DB::connection('steve')
                ->table('transaction')
                ->where('transaction_pk', $transactionId)
                ->value('start_value');
            
            if (is_null($txStart)) {
                $txStart = DB::connection('steve')
                    ->table('transaction_start')
                    ->where('transaction_pk', $transactionId)
                    ->value('start_value');
            }
            
            if (is_null($txStart)) {
                // Fallback to the very first meter value for this TX
                $txStart = DB::connection('steve')
                    ->table('connector_meter_value')
                    ->where('transaction_pk', $transactionId)
                    ->orderBy('value_timestamp', 'asc')
                    ->value('value');
            }
            $startValue = (float) ($txStart ?? 0);

            $foundPower = false;
            $foundEnergy = false;
            $foundSoc = false;

            // 2. Fetch prices to calculate live cost
            $tariff = \App\Models\Tariff::resolveForStation($session->station);
            $prices = $tariff ? $tariff->getCurrentPrices() : ['price_session' => 0, 'price_kwh' => 0];
            $energyPrice = (float) ($session->energy_price_per_kwh ?? $prices['price_kwh']);
            $sessionFee = (float) ($session->session_fee ?? $prices['price_session']);
            $discount = (float) ($session->discount_amount ?? 0);

            foreach ($latestValues as $mv) {
                $m = strtolower($mv->measurand ?? '');
                $v = (float) $mv->value;
                $unit = strtoupper($mv->unit ?? '');

                if ($v <= 0) continue; // Skip empty or zero metrics to find the real ones

                // Power (kW) - Priority to Import
                if (!$foundPower && (str_contains($m, 'power') || str_contains($m, 'current'))) {
                    if (str_contains($m, 'export') || str_contains($m, 'offered')) continue;

                    if (str_contains($m, 'current')) {
                        // Estimate power if only current is available (A * 220V / 1000)
                        $metrics['power_kw'] = round(($v * 220) / 1000, 2);
                    } else {
                        // Handle Watts vs KiloWatts
                        $metrics['power_kw'] = round($v > 100 || $unit === 'W' ? $v / 1000 : $v, 2);
                    }
                    $foundPower = true;
                }
                
                // Energy (Session Consumed kWh)
                if (!$foundEnergy && str_contains($m, 'energy')) {
                    if (str_contains($m, 'export')) continue;
                    
                    $sessionWh = max(0, $v - $startValue);
                    $metrics['energy_kwh'] = round($sessionWh > 500 || $unit === 'WH' ? $sessionWh / 1000 : $sessionWh, 2);
                    $foundEnergy = true;
                }
                
                // SoC (Battery %)
                if (!$foundSoc && str_contains($m, 'soc')) {
                    $metrics['soc'] = (int) $v;
                    $foundSoc = true;
                }

                if ($foundPower && $foundEnergy && $foundSoc) break;
            }

            // 3. RECALCULATE LIVE COST (v2.0)
            // Capped discount logic applied here too
            $liveEnergyCost = $metrics['energy_kwh'] * $energyPrice;
            $cappedDiscount = min($discount, $sessionFee);
            $metrics['total_cost'] = round(max(0, $liveEnergyCost + $sessionFee - $cappedDiscount), 2);

            // FALLBACK: If power is missing...
            if (!$foundPower && $foundEnergy && $latestValues->count() > 5) {
                $firstEnergy = $latestValues->where('measurand', 'Energy.Active.Import.Register')->first();
                $oldEnergy = $latestValues->where('measurand', 'Energy.Active.Import.Register')->last();
                
                if ($firstEnergy && $oldEnergy && $firstEnergy->value_timestamp != $oldEnergy->value_timestamp) {
                    $diffWh = (float)$firstEnergy->value - (float)$oldEnergy->value;
                    $timeHours = \Carbon\Carbon::parse($firstEnergy->value_timestamp)->diffInSeconds(\Carbon\Carbon::parse($oldEnergy->value_timestamp)) / 3600;
                    if ($timeHours > 0) {
                        $metrics['power_kw'] = round(($diffWh / 1000) / $timeHours, 2);
                    }
                }
            }

            return $metrics;
        } catch (\Throwable $e) {
            Log::error('Error fetching SteVe metrics', ['tx' => $transactionId, 'error' => $e->getMessage()]);
            return [
                'power_kw' => 0, 
                'energy_kwh' => round((float) $session->total_energy_kwh, 2), 
                'soc' => $session->current_soc, 
                'total_cost' => round((float) $session->total_cost, 2),
                'error' => true
            ];
        }
    }

    public function start(Request $request, Station $station)
    {
        $request->validate([
            'connector_id' => 'required|integer|min:1',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
        ]);

        $user = $request->user();
        
        if (empty($user->billing_document)) {
            return response()->json([
                'message' => 'Es necesario registrar tu documento de identidad (CI/NIT) en tu perfil para poder iniciar la carga de energía.',
                'status' => 'billing_document_required',
            ], 422);
        }
        
        // --- Vehicle Restriction Policy Enforcement ---
        $settings = \App\Models\SystemSetting::get();
        $restrictCharging = (bool) ($settings->restrict_charging_without_vehicle ?? false);
        $vehicleId = $request->input('vehicle_id');
        $vehicle = null;

        if ($restrictCharging) {
            $userVehiclesCount = $user->vehicles()->count();
            if ($userVehiclesCount === 0) {
                return response()->json([
                    'message' => 'Es necesario registrar al menos un vehículo y una placa en tu perfil para poder iniciar la carga de energía.',
                    'status' => 'vehicle_required',
                    'has_vehicles' => false
                ], 422);
            }

            if (!$vehicleId || !$user->vehicles()->where('id', $vehicleId)->exists()) {
                return response()->json([
                    'message' => 'Por favor selecciona un vehículo registrado con placa válida para iniciar la carga.',
                    'status' => 'vehicle_required',
                    'has_vehicles' => true
                ], 422);
            }
        }

        if ($vehicleId) {
            $vehicle = $user->vehicles()->find($vehicleId);
            if (!$vehicle) {
                return response()->json([
                    'message' => 'El vehículo seleccionado no es válido o no pertenece a tu cuenta.',
                    'status' => 'error'
                ], 422);
            }
        }

        $tag = RfidTag::where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_virtual', true)
            ->first();

        if (!$tag) {
            return response()->json([
                'message' => 'No tienes RFID tag activo. Contacta al administrador.',
                'status' => 'error',
            ], 422);
        }

        $connectorId = (int) $request->input('connector_id');

        // 1. Balance Validation (v3.1)
        // Set to a flat 14.90 BOB to ensure users with exactly 15.00 BOB (app-enforced minimum)
        // do not get blocked by tiny floating-point database decimal rounding differences.
        $tariff = \App\Models\Tariff::resolveForStation($station);
        $currentPrices = $tariff ? $tariff->getCurrentPrices() : ['price_session' => 0, 'price_kwh' => 0, 'currency' => 'BOB'];
        
        $minRequired = 14.90; 
        
        if ($user->balance < $minRequired) {
            $currency = $currentPrices['currency'] ?? 'BOB';
            return response()->json([
                'message' => "Saldo insuficiente para iniciar.",
                'detail' => "Se requiere un saldo mínimo de {$currency} 15.00 para iniciar la carga.",
                'status' => 'insufficient_balance',
                'balance' => (float) $user->balance,
                'required' => 15.00,
            ], 402);
        }

        $steve = app(SteveService::class);
        $result = $steve->remoteStart($station->charge_box_id, $connectorId, $tag->tag_code, $user->id);
        
        if (!$result['ok']) {
            return response()->json([
                'message' => 'No se pudo iniciar la carga en SteVe',
                'detail' => $result['detail'],
                'status' => 'error',
            ], 502);
        }

        // 2. Create Transition Session (Starting)
        // This record signals the App to show the "Iniciando" card
        try {
            $connector = \App\Models\Connector::where('station_id', $station->id)
                ->where('connector_id', $connectorId)
                ->first();

            ChargingSession::create([
                'user_id' => $user->id,
                'status' => 'Starting',
                'station_id' => $station->id,
                'connector_id' => $connector?->id ?? 1,
                'rfid_tag_id' => $tag->id,
                'tariff_id' => $station->tariff_id,
                'start_time' => now(),
                'total_energy_kwh' => 0.0,
                'total_cost' => 0.0,
                'currency' => $station->tariff->currency ?? 'BOB',
                'vehicle_id' => $vehicle?->id,
                'vehicle_brand' => $vehicle?->brand,
                'vehicle_model' => $vehicle?->model,
                'vehicle_plate' => $vehicle?->plate,
            ]);
        } catch (\Throwable $e) {
            Log::error('Transition session creation failed', ['error' => $e->getMessage()]);
        }

        // Notify the user
        try {
            $user->notify(new \App\Notifications\GeneralNotification(
                'Carga solicitada',
                "Se ha enviado la orden de inicio a la estación {$station->name} (Conector {$connectorId}).",
                ['type' => 'CHARGING_START', 'station_id' => $station->id]
            ));
        } catch (\Throwable $e) {
            Log::error('Notification error in Charging start', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Solicitud de inicio enviada al cargador',
            'station' => $station->name,
            'status' => 'requested',
            'charge_box_id' => $station->charge_box_id,
            'connector_id' => $connectorId,
            'tag' => $tag->tag_code,
        ]);
    }

    public function stop(Request $request, Station $station)
    {
        $request->validate([
            'transaction_id' => 'nullable|integer|min:1',
        ]);

        $txId = $request->input('transaction_id');

        if (!$txId) {
            $activeSession = ChargingSession::where('user_id', $request->user()->id)
                ->where('station_id', $station->id)
                ->whereIn('status', ['Active', 'Starting'])
                ->orderByDesc('created_at')
                ->first();

            $txId = $activeSession?->transaction_id;
        }

        if (!$txId) {
            return response()->json([
                'message' => 'No se encontró transacción activa para esta estación',
                'status' => 'idle',
            ], 404);
        }

        $steve = app(SteveService::class);
        $result = $steve->remoteStop($station->charge_box_id, (int) $txId, $request->user()->id);
        
        if (!$result['ok']) {
            return response()->json([
                'message' => 'No se pudo detener la carga en SteVe',
                'detail' => $result['detail'],
                'status' => 'error',
            ], 502);
        }

        // Notify the user
        try {
            $request->user()->notify(new \App\Notifications\GeneralNotification(
                'Carga detenida',
                "Se ha enviado la orden de parada a la estación {$station->name}.",
                ['type' => 'CHARGING_STOP', 'station_id' => $station->id]
            ));
        } catch (\Throwable $e) {
            Log::error('Notification error in Charging stop', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Solicitud de parada enviada al cargador',
            'station' => $station->name,
            'status' => 'requested',
            'transaction_id' => (int) $txId,
        ]);
    }

    public function cancel(Request $request, ChargingSession $session)
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($session->status !== 'Starting') {
            return response()->json([
                'message' => 'Solo se pueden cancelar sesiones en estado de inicio.',
                'current_status' => $session->status
            ], 422);
        }

        $session->update([
            'status' => 'Failed',
            'stop_time' => now(),
            'stop_reason' => 'UserCancelled'
        ]);

        return response()->json([
            'message' => 'Solicitud de inicio cancelada exitosamente.',
            'status' => 'cancelled'
        ]);
    }

    public function downloadInvoice(Request $request, ChargingSession $session)
    {
        $user = $request->user();
        
        // Manual auth for direct link downloads if sanctum fails
        if (!$user && $request->has('token')) {
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user || ($session->user_id !== $user->id && !$user->is_admin)) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        if ($session->status !== 'Completed') {
            return response()->json(['message' => 'La carga aún no ha finalizado'], 422);
        }

        $pdf = Pdf::loadView('pdf.session_invoice', [
            'record' => $session,
            'user' => $user,
        ]);

        return $pdf->download("Factura_Carga_{$session->id}.pdf");
    }
}

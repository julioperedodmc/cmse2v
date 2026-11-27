<?php

namespace App\Http\Controllers\Api\V1\Mobile\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Mobile\Reports\Traits\HasReportFilters;
use App\Models\ChargingSession;
use Illuminate\Http\Request;

class OperationsReportController extends Controller
{
    use HasReportFilters;

    /**
     * Endpoint: Operación de Cargas (Fallas, Horarios, Intentos)
     */
    public function getOperations(Request $request)
    {
        $cacheKey = 'report_operations_' . md5(json_encode($request->all()));

        $response = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(3), function () use ($request) {
            $baseQuery = ChargingSession::query()->whereIn('charging_sessions.status', ['Completed', 'Failed']);
            $this->applyFilters($baseQuery, $request);

            $localTimeExpr = 'DATE_SUB(charging_sessions.start_time, INTERVAL 4 HOUR)';

        $dailyAttemptsQuery = (clone $baseQuery)
            ->selectRaw("
                DATE($localTimeExpr) as date,
                SUM(CASE WHEN charging_sessions.status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN charging_sessions.status = 'Failed' THEN 1 ELSE 0 END) as failed_count,
                COUNT(charging_sessions.id) as total_attempts
            ")
            ->groupBy('date')
            ->orderBy('date', 'desc');

        if (!$request->query('startDate') && !$request->query('endDate') && !$request->query('month')) {
            $dailyAttemptsQuery->limit(30);
        }

        $dailyAttempts = $dailyAttemptsQuery->get();

        $failuresByStation = (clone $baseQuery)
            ->where('charging_sessions.status', 'Failed')
            ->leftJoin('stations', 'charging_sessions.station_id', '=', 'stations.id')
            ->selectRaw('
                stations.id as station_id,
                IFNULL(stations.name, "Estación Desconocida") as station_name,
                COUNT(charging_sessions.id) as failed_count,
                IFNULL(charging_sessions.stop_reason, "No Especificado") as stop_reason
            ')
            ->groupBy('stations.id', 'stations.name', 'charging_sessions.stop_reason')
            ->orderByDesc('failed_count')
            ->get();

        $hourlyUsageMap = (clone $baseQuery)
            ->selectRaw("
                HOUR($localTimeExpr) as hour,
                COUNT(charging_sessions.id) as total_attempts,
                SUM(CASE WHEN charging_sessions.status = 'Completed' THEN 1 ELSE 0 END) as completed_count
            ")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $hourlyHeatmap = [];
        for ($h = 0; $h < 24; $h++) {
            $data = $hourlyUsageMap->get($h);
            $attempts = $data->total_attempts ?? 0;
            $completed = $data->completed_count ?? 0;
            $hourlyHeatmap[] = [
                'hour' => $h,
                'formatted_hour' => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00',
                'attempts' => $attempts,
                'completed' => $completed,
                'success_rate' => $attempts > 0 ? round(($completed / $attempts) * 100, 1) : 0,
            ];
        }

        $connectorUsage = (clone $baseQuery)
            ->leftJoin('connectors', 'charging_sessions.connector_id', '=', 'connectors.id')
            ->selectRaw('
                IFNULL(NULLIF(connectors.type, ""), "Desconocido") as connector_type,
                COUNT(charging_sessions.id) as sessions_count,
                SUM(CASE WHEN charging_sessions.status = "Completed" THEN 1 ELSE 0 END) as completed_count
            ')
            ->groupByRaw('IFNULL(NULLIF(connectors.type, ""), "Desconocido")')
            ->get();

        // Carga ansiosa incluyendo vehículos para extraer la placa
        $eagerRelations = ['station', 'user.vehicles', 'rfidTag.user.vehicles'];
        if (method_exists(ChargingSession::class, 'vehicle')) {
            $eagerRelations[] = 'vehicle';
        }

        $latestSessions = (clone $baseQuery)
            ->with($eagerRelations)
            ->leftJoin('connectors', 'charging_sessions.connector_id', '=', 'connectors.id')
            ->select('charging_sessions.*')
            ->selectRaw('IFNULL(NULLIF(connectors.type, ""), "Desconocido") as mapped_connector_type')
            ->orderBy('charging_sessions.start_time', 'desc')
            ->limit(1000)
            ->get()
            ->map(function ($s) {
                // 1. Extraer placa directamente si existe en la sesión
                $plate = $s->vehicle_plate ?? $s->plate ?? $s->license_plate ?? $s->placa ?? null;

                // 2. Si la sesión tiene relación directa con vehículo
                if (!$plate && isset($s->vehicle) && $s->vehicle) {
                    $plate = $s->vehicle->plate ?? $s->vehicle->license_plate ?? $s->vehicle->placa ?? null;
                }

                // 3. Si no, extraer de los vehículos registrados del usuario
                if (!$plate && $s->user && $s->user->vehicles && $s->user->vehicles->isNotEmpty()) {
                    $plates = $s->user->vehicles->map(function ($v) {
                        return $v->plate ?? $v->license_plate ?? $v->placa ?? null;
                    })->filter()->unique()->values();

                    if ($plates->isNotEmpty()) {
                        $plate = $plates->implode(', ');
                    }
                }

                // 4. Si fue sesión iniciada por tarjeta RFID, buscar por el usuario del RFID
                if (!$plate && $s->rfidTag && $s->rfidTag->user && $s->rfidTag->user->vehicles && $s->rfidTag->user->vehicles->isNotEmpty()) {
                    $plates = $s->rfidTag->user->vehicles->map(function ($v) {
                        return $v->plate ?? $v->license_plate ?? $v->placa ?? null;
                    })->filter()->unique()->values();

                    if ($plates->isNotEmpty()) {
                        $plate = $plates->implode(', ');
                    }
                }

                if (empty($plate)) {
                    $plate = 'S/P'; // Sin Placa / Particular
                }

                $startLocal = $s->start_time ? \Carbon\Carbon::parse($s->start_time)->setTimezone('America/La_Paz') : null;
                $stopLocal = $s->stop_time ? \Carbon\Carbon::parse($s->stop_time)->setTimezone('America/La_Paz') : null;

                $rawFee = (float) ($s->session_fee ?? 0);
                $discount = (float) ($s->discount_amount ?? 0);
                $feeDiscount = min($discount, $rawFee);
                $netFee = max(0.0, $rawFee - $feeDiscount);

                return [
                    'id' => $s->id,
                    'transaction_id' => $s->transaction_id,
                    'station_name' => $s->station?->name ?? 'N/A',
                    'user_name' => $s->user?->name ?? 'Anónimo/Card',
                    'plate' => $plate,
                    'placa' => $plate, // Soporte alternativo para el frontend
                    'status' => $s->status,
                    'start_time' => $startLocal ? $startLocal->toDateTimeString() : null,
                    'stop_time' => $stopLocal ? $stopLocal->toDateTimeString() : null,
                    'fecha_local_corregida' => $startLocal ? $startLocal->toDateTimeString() : 'N/A',
                    'fecha_ajustada' => true,
                    'start_time_utc' => $s->start_time ? \Carbon\Carbon::parse($s->start_time)->toDateTimeString() : null,
                    'stop_time_utc' => $s->stop_time ? \Carbon\Carbon::parse($s->stop_time)->toDateTimeString() : null,
                    'energy_kwh' => (float) $s->total_energy_kwh,
                    'parking_fee_gross_bob' => $rawFee,
                    'parking_fee_discount_bob' => $feeDiscount,
                    'parking_fee_bob' => $netFee,
                    'energy_cost_bob' => (float) ($s->energy_cost ?? 0),
                    'total_cost_bob' => (float) $s->total_cost,
                    'stop_reason' => $s->stop_reason,
                    'connector_type' => $s->mapped_connector_type,
                ];
            });

            return [
                'success' => true,
                'daily_attempts' => $dailyAttempts,
                'failures_by_station' => $failuresByStation,
                'hourly_heatmap' => $hourlyHeatmap,
                'connector_usage' => $connectorUsage,
                'latest_sessions' => $latestSessions,
            ];
        });

        return response()->json($response);
    }

    /**
     * Endpoint: Exportar TODAS las sesiones del rango a CSV / Excel sin límites
     */
    public function exportCsv(Request $request)
    {
        $baseQuery = ChargingSession::query()->whereIn('charging_sessions.status', ['Completed', 'Failed']);
        $this->applyFilters($baseQuery, $request);

        $eagerRelations = ['station', 'user.vehicles', 'rfidTag.user.vehicles'];
        if (method_exists(ChargingSession::class, 'vehicle')) {
            $eagerRelations[] = 'vehicle';
        }

        $fileName = 'Operaciones_de_Cargas_' . date('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($baseQuery, $eagerRelations) {
            $handle = fopen('php://output', 'w');
            
            // UTF-8 BOM para que Excel en español lo abra correctamente con tildes y caracteres especiales
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Encabezados usando separador punto y coma (;) estándar en Excel español/latinoamérica
            fputcsv($handle, [
                'ID Sesión',
                'Fecha de Inicio',
                'Estación',
                'Conector',
                'Cliente',
                'Placa',
                'Energía (kWh)',
                'Costo Energía (BOB)',
                'Fee Parqueo Lista (BOB)',
                'Descuento Fee (BOB)',
                'Fee Parqueo Neto (BOB)',
                'Monto Total (BOB)',
                'Estado',
                'Motivo de Corte',
            ], ';');

            // Procesamiento en lotes de 500 para rendimiento óptimo sin saturar memoria
            $query = (clone $baseQuery)
                ->with($eagerRelations)
                ->leftJoin('connectors', 'charging_sessions.connector_id', '=', 'connectors.id')
                ->select('charging_sessions.*')
                ->selectRaw('IFNULL(NULLIF(connectors.type, ""), "Desconocido") as mapped_connector_type')
                ->orderBy('charging_sessions.start_time', 'desc');

            $query->chunk(500, function ($sessions) use ($handle) {
                foreach ($sessions as $s) {
                    $plate = $s->vehicle_plate ?? $s->plate ?? $s->license_plate ?? $s->placa ?? null;
                    if (!$plate && isset($s->vehicle) && $s->vehicle) {
                        $plate = $s->vehicle->plate ?? $s->vehicle->license_plate ?? $s->vehicle->placa ?? null;
                    }
                    if (!$plate && $s->user && $s->user->vehicles && $s->user->vehicles->isNotEmpty()) {
                        $plates = $s->user->vehicles->map(fn($v) => $v->plate ?? $v->license_plate ?? $v->placa ?? null)->filter()->unique()->values();
                        if ($plates->isNotEmpty()) {
                            $plate = $plates->implode(', ');
                        }
                    }
                    if (!$plate && $s->rfidTag && $s->rfidTag->user && $s->rfidTag->user->vehicles && $s->rfidTag->user->vehicles->isNotEmpty()) {
                        $plates = $s->rfidTag->user->vehicles->map(fn($v) => $v->plate ?? $v->license_plate ?? $v->placa ?? null)->filter()->unique()->values();
                        if ($plates->isNotEmpty()) {
                            $plate = $plates->implode(', ');
                        }
                    }
                    if (empty($plate)) {
                        $plate = 'S/P';
                    }

                    $startLocal = $s->start_time ? \Carbon\Carbon::parse($s->start_time)->setTimezone('America/La_Paz')->toDateTimeString() : '-';
                    $rawFee = (float) ($s->session_fee ?? 0);
                    $discount = (float) ($s->discount_amount ?? 0);
                    $feeDiscount = min($discount, $rawFee);
                    $netFee = max(0.0, $rawFee - $feeDiscount);
                    $totalCost = (float) $s->total_cost;
                    $energyCost = (float) ($s->energy_cost ?? ($totalCost - $netFee));

                    fputcsv($handle, [
                        'CS-' . ($s->id ?: $s->transaction_id),
                        $startLocal,
                        $s->station?->name ?? 'N/A',
                        $s->mapped_connector_type ?? 'Desconocido',
                        $s->user?->name ?? 'Anónimo/Card',
                        $plate,
                        number_format((float) $s->total_energy_kwh, 2, '.', ''),
                        number_format($energyCost, 2, '.', ''),
                        number_format($rawFee, 2, '.', ''),
                        number_format($feeDiscount, 2, '.', ''),
                        number_format($netFee, 2, '.', ''),
                        number_format($totalCost, 2, '.', ''),
                        $s->status === 'Completed' ? 'Completada' : 'Fallida',
                        $s->stop_reason ?: '-',
                    ], ';');
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}

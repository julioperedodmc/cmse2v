<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\Company;
use App\Models\Connector;
use App\Models\RfidTag;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecutiveReportController extends Controller
{
    /**
     * Endpoint: Opciones de filtrado dinámico
     */
    public function getFilterOptions()
    {
        $stations = Station::select('id', 'name')->get();
        $connectorTypes = Connector::distinct()->pluck('type')->filter()->values()->toArray();
        $vehicleBrands = Vehicle::distinct()->pluck('brand')->filter()->values()->toArray();
        $companies = \App\Models\Company::select('id', 'name')->get();

        return response()->json([
            'success' => true,
            'stations' => $stations,
            'connector_types' => $connectorTypes,
            'vehicle_brands' => $vehicleBrands,
            'companies' => $companies,
        ]);
    }

    /**
     * Helper centralizado para aplicar filtros a la consulta de charging_sessions.
     */
    protected function applyFilters($query, Request $request)
    {
        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');
        $month = $request->query('month'); // YYYY-MM
        $stationId = $request->query('stationId');
        $connectorType = $request->query('connectorType');
        $clientId = $request->query('clientId');
        $rfidCode = $request->query('rfidCode');
        $vehicleBrand = $request->query('vehicleBrand');

        $localTimeExpr = 'charging_sessions.start_time';

        if ($month) {
            $carbonMonth = Carbon::parse($month . '-01');
            $query->whereRaw("DATE($localTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                  ->whereRaw("DATE($localTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
        } else {
            if ($startDate) {
                $query->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
            }
            if ($endDate) {
                $query->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
            }
        }

        if ($stationId) {
            $query->where('charging_sessions.station_id', $stationId);
        }

        if ($clientId) {
            $query->where('charging_sessions.user_id', $clientId);
        }

        if ($vehicleBrand) {
            $query->whereHas('user.vehicles', function ($q) use ($vehicleBrand) {
                $q->where('brand', 'LIKE', '%' . $vehicleBrand . '%');
            });
        }

        if ($rfidCode) {
            $query->whereHas('rfidTag', function ($q) use ($rfidCode) {
                $q->where('tag_code', $rfidCode);
            });
        }

        if ($connectorType) {
            $query->whereExists(function ($q) use ($connectorType) {
                $q->select(DB::raw(1))
                  ->from('connectors')
                  ->whereColumn('connectors.id', 'charging_sessions.connector_id')
                  ->where('connectors.type', $connectorType);
            });
        }

        return $query;
    }

    /**
     * Endpoint 1: Resumen Ejecutivo Principal
     */
    public function getExecutiveSummary(Request $request)
    {
        $baseQuery = ChargingSession::query()->whereIn('charging_sessions.status', ['Completed', 'Failed']);
        $this->applyFilters($baseQuery, $request);

        $attemptedSessions = (clone $baseQuery)->count();
        $completedQuery = (clone $baseQuery)->where('charging_sessions.status', 'Completed');
        $completedSessions = (clone $completedQuery)->count();
        $failedSessions = (clone $baseQuery)->where('charging_sessions.status', 'Failed')->count();
        $successRate = $attemptedSessions > 0 ? round(($completedSessions / $attemptedSessions) * 100, 2) : 0;

        $kpiData = (clone $completedQuery)->selectRaw('
            SUM(total_energy_kwh) as total_energy,
            SUM(total_cost) as total_revenue,
            SUM(energy_cost) as total_energy_cost
        ')->first();

        $totalEnergyKwh = round($kpiData->total_energy ?? 0, 1);
        $totalEnergyMwh = round($totalEnergyKwh / 1000, 3);
        $totalRevenueBob = round($kpiData->total_revenue ?? 0, 2);
        $totalEnergyCost = $kpiData->total_energy_cost ?? 0;
        $avgBobPerKwh = $totalEnergyKwh > 0 ? round($totalEnergyCost / $totalEnergyKwh, 2) : 0;

        // Calculate total recharged amount (Total Facturado App Billetera)
        // Calculates Libélula and all other recharge processes, excluding users without NIT or CI (and temporary RFID users)
        $rechargesQuery = DB::table('wallet_transactions')
            ->where('type', 'RECHARGE')
            ->where('status', 'Completed')
            ->whereIn('user_id', function($q) {
                $q->select('id')
                  ->from('users')
                  ->where('is_admin', 0)
                  ->whereNotExists(function($query) {
                      $query->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User')
                            ->where('roles.name', '<>', 'client');
                  })
                  ->whereNotNull('billing_document')
                  ->where('billing_document', '<>', '')
                  ->where('billing_document', '<>', '0')
                  ->where('name', 'NOT LIKE', 'Usuario RFID%')
                  ->where('email', 'NOT LIKE', '%@evce.temp');
            });

        // Calculate total excluded recharge amount (Completed recharges for users without NIT/CI/temp RFID)
        $excludedRechargesQuery = DB::table('wallet_transactions')
            ->where('type', 'RECHARGE')
            ->where('status', 'Completed')
            ->whereIn('user_id', function($q) {
                $q->select('id')
                  ->from('users')
                  ->where('is_admin', 0)
                  ->whereNotExists(function($query) {
                      $query->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User')
                            ->where('roles.name', '<>', 'client');
                  })
                  ->where(function($sub) {
                      $sub->whereNull('billing_document')
                          ->orWhere('billing_document', '')
                          ->orWhere('billing_document', '0')
                          ->orWhere('name', 'LIKE', 'Usuario RFID%')
                          ->orWhere('email', 'LIKE', '%@evce.temp');
                  });
            });

        // Calculate total recharged amount for enterprise/dealerships (payment_method CREDITO, status PENDING, and valid invoice_url)
        $creditQuery = DB::table('wallet_transactions')
            ->where('payment_method', 'CREDITO')
            ->where('status', 'PENDING')
            ->whereNotNull('invoice_url')
            ->where('invoice_url', '<>', '');

        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');
        $month = $request->query('month');
        $localTimeExpr = 'DATE_SUB(created_at, INTERVAL 4 HOUR)';

        if ($month) {
            $carbonMonth = Carbon::parse($month . '-01');
            $rechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                           ->whereRaw("DATE($localTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
            $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                                   ->whereRaw("DATE($localTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
            $creditQuery->whereRaw("DATE($localTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                        ->whereRaw("DATE($localTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
        } else {
            if ($startDate) {
                $rechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
                $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
                $creditQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
            }
            if ($endDate) {
                $rechargesQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
                $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
                $creditQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
            }
        }
        $totalFacturado = (float) $rechargesQuery->sum('amount');
        $totalExcluido = (float) $excludedRechargesQuery->sum('amount');
        $totalFacturadoCredito = (float) $creditQuery->sum('amount');

        $uniqueClients = (clone $completedQuery)->distinct('user_id')->count('user_id');
        $registeredVehicles = Vehicle::count();
        $activeStations = Station::where('is_active', true)->count();

        $connectorsUsed = (clone $completedQuery)
            ->leftJoin('connectors', 'charging_sessions.connector_id', '=', 'connectors.id')
            ->selectRaw('IFNULL(NULLIF(connectors.type, ""), "Desconocido") as type, COUNT(charging_sessions.id) as count')
            ->groupBy('connectors.type')
            ->pluck('count', 'type')
            ->toArray();

        $monthlyChart = (clone $completedQuery)
            ->selectRaw('
                DATE_FORMAT(DATE_SUB(charging_sessions.start_time, INTERVAL 4 HOUR), "%Y-%m") as month,
                ROUND(SUM(charging_sessions.total_energy_kwh), 1) as kwh,
                ROUND(SUM(charging_sessions.total_cost), 2) as revenue_bob,
                COUNT(charging_sessions.id) as sessions
            ')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $stationRanking = (clone $completedQuery)
            ->leftJoin('stations', 'charging_sessions.station_id', '=', 'stations.id')
            ->selectRaw('
                stations.id as station_id,
                IFNULL(stations.name, "Estación Eliminada") as station_name,
                COUNT(charging_sessions.id) as sessions_count,
                ROUND(SUM(charging_sessions.total_energy_kwh), 1) as energy_kwh,
                ROUND(SUM(charging_sessions.total_cost), 2) as revenue_bob
            ')
            ->groupBy('stations.id', 'stations.name')
            ->orderByDesc('sessions_count')
            ->limit(10)
            ->get();

        $connectorRanking = (clone $completedQuery)
            ->leftJoin('connectors', 'charging_sessions.connector_id', '=', 'connectors.id')
            ->selectRaw('
                IFNULL(NULLIF(connectors.type, ""), "Desconocido") as connector_type,
                COUNT(charging_sessions.id) as sessions_count,
                ROUND(SUM(charging_sessions.total_energy_kwh), 1) as energy_kwh,
                ROUND(SUM(charging_sessions.total_cost), 2) as revenue_bob
            ')
            ->groupBy('connectors.type')
            ->orderByDesc('sessions_count')
            ->get();

        return response()->json([
            'success' => true,
            'kpis' => [
                'attempted_sessions' => $attemptedSessions,
                'completed_sessions' => $completedSessions,
                'failed_sessions' => $failedSessions,
                'success_rate_percent' => $successRate,
                'total_energy_kwh' => $totalEnergyKwh,
                'total_energy_mwh' => $totalEnergyMwh,
                'total_revenue_bob' => round($totalEnergyCost, 2),
                'total_facturado_bob' => round($totalFacturado, 2),
                'total_excluido_bob' => round($totalExcluido, 2),
                'total_facturado_credito_bob' => round($totalFacturadoCredito, 2),
                'avg_bob_per_kwh' => $avgBobPerKwh,
                'unique_clients' => $uniqueClients,
                'registered_vehicles' => $registeredVehicles,
                'active_stations' => $activeStations,
                'connectors_used' => $connectorsUsed,
            ],
            'charts' => [
                'monthly_breakdown' => $monthlyChart,
                'station_ranking' => $stationRanking,
                'connector_ranking' => $connectorRanking,
            ]
        ]);
    }

    /**
     * Endpoint 2: Facturación y Energía AETN (Bloques Tarifarios)
     */
    public function getAetnBilling(Request $request)
    {
        try {
            $hasCustomRates = $request->has('rateBajo') || $request->has('rateMedio') || $request->has('rateAlto');

            // Dynamically resolve reference tariff strictly by valid_from and valid_until for requested month/period
            $filterMonth = $request->query('month');
            $startDate = $request->query('startDate');
            if (!$filterMonth && $startDate) {
                $filterMonth = substr($startDate, 0, 7);
            }
            if (!$filterMonth) {
                $filterMonth = now()->setTimezone('America/La_Paz')->format('Y-m');
            }

            $mStart = $filterMonth . '-01 00:00:00';
            $mEnd = date('Y-m-t 23:59:59', strtotime($filterMonth . '-01'));

            $activeTariff = \App\Models\Tariff::where(function($q) use ($mEnd) {
                    $q->whereNull('valid_from')
                      ->orWhere('valid_from', '<=', $mEnd);
                })
                ->where(function($q) use ($mStart) {
                    $q->whereNull('valid_until')
                      ->orWhere('valid_until', '>=', $mStart);
                })
                ->orderBy('valid_from', 'desc')
                ->first();

            $defaultBajo = $activeTariff && $activeTariff->b1_price_kwh !== null ? (float)$activeTariff->b1_price_kwh : 0.0;
            $defaultMedio = $activeTariff && $activeTariff->b2_price_kwh !== null ? (float)$activeTariff->b2_price_kwh : 0.0;
            $defaultAlto = $activeTariff && $activeTariff->b3_price_kwh !== null ? (float)$activeTariff->b3_price_kwh : 0.0;

        $reqBajo = (float) $request->query('rateBajo', $defaultBajo);
        $reqMedio = (float) $request->query('rateMedio', $defaultMedio);
        $reqAlto = (float) $request->query('rateAlto', $defaultAlto);

        $query = ChargingSession::query()->where('charging_sessions.status', 'Completed');
        $this->applyFilters($query, $request);

        // Fetching all completed sessions matching filters. To optimize performance, we select only necessary fields.
        $sessions = $query->with('station')
            ->select('id', 'start_time', 'stop_time', 'total_energy_kwh', 'applied_tariff_snapshot', 'tariff_id', 'station_id')
            ->get();

        $billingService = new \App\Services\BillingService();

        $monthlyData = [];

        foreach ($sessions as $session) {
            $localStart = \Carbon\Carbon::parse($session->start_time)->setTimezone('America/La_Paz');
            $month = $localStart->format('Y-m');

            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = [
                    'kwh_bajo' => 0.0,
                    'kwh_medio' => 0.0,
                    'kwh_alto' => 0.0,
                    'total_kwh' => 0.0,
                    'tariffs' => [],
                ];
            }

            // Retrieve breakdown
            $breakdown = null;
            if ($session->applied_tariff_snapshot) {
                $snapshot = is_array($session->applied_tariff_snapshot) 
                    ? $session->applied_tariff_snapshot 
                    : json_decode($session->applied_tariff_snapshot, true);

                $breakdown = $snapshot['billing_breakdown'] ?? null;
            }

            if (!$breakdown) {
                // Calculate dynamically
                try {
                    $pricing = $billingService->calculateSessionCost($session, (float)$session->total_energy_kwh, $session->stop_time);
                    $breakdown = $pricing['breakdown'] ?? [];
                } catch (\Exception $e) {
                    $breakdown = [];
                }
            }

            $sessionKwhBajo = 0.0;
            $sessionKwhMedio = 0.0;
            $sessionKwhAlto = 0.0;

            foreach ($breakdown as $item) {
                $blockIdx = (int)($item['block'] ?? 1);
                $kwh = (float)($item['energy_kwh'] ?? 0);
                if ($blockIdx === 1) {
                    $sessionKwhBajo += $kwh;
                } elseif ($blockIdx === 2 || $blockIdx === 4) {
                    $sessionKwhMedio += $kwh;
                } elseif ($blockIdx === 3) {
                    $sessionKwhAlto += $kwh;
                } else {
                    // Default fallback for any block index >= 5
                    $sessionKwhBajo += $kwh;
                }
            }

            // Fallback to legacy hour ranges if breakdown is empty and session had energy
            $totalSessionKwh = (float)$session->total_energy_kwh;
            if ($sessionKwhBajo + $sessionKwhMedio + $sessionKwhAlto <= 0 && $totalSessionKwh > 0) {
                $hour = (int)$localStart->format('H');
                if ($hour >= 23 || $hour < 7) {
                    $sessionKwhBajo = $totalSessionKwh;
                } elseif ($hour >= 18 && $hour < 21) {
                    $sessionKwhAlto = $totalSessionKwh;
                } else {
                    $sessionKwhMedio = $totalSessionKwh;
                }
            }

            $monthlyData[$month]['kwh_bajo'] += $sessionKwhBajo;
            $monthlyData[$month]['kwh_medio'] += $sessionKwhMedio;
            $monthlyData[$month]['kwh_alto'] += $sessionKwhAlto;
            $monthlyData[$month]['total_kwh'] += $totalSessionKwh;

            if ($session->tariff_id) {
                $monthlyData[$month]['tariffs'][] = $session->tariff_id;
            }
        }

        ksort($monthlyData);

        $resultMonthly = [];
        $totalKwhBajo = 0; $totalKwhMedio = 0; $totalKwhAlto = 0; $grandTotalKwh = 0;
        $totalGrossRevenue = 0;
        $latestRates = ['bajo' => $reqBajo, 'medio' => $reqMedio, 'alto' => $reqAlto];

        foreach ($monthlyData as $month => $data) {
            $kBajo = round($data['kwh_bajo'], 1);
            $kMedio = round($data['kwh_medio'], 1);
            $kAlto = round($data['kwh_alto'], 1);
            $kTotal = round($data['total_kwh'], 1);

            if ($hasCustomRates) {
                $mBajo = $reqBajo;
                $mMedio = $reqMedio;
                $mAlto = $reqAlto;
            } else {
                $monthStart = $month . '-01 00:00:00';
                $monthEnd = date('Y-m-t 23:59:59', strtotime($month . '-01'));

                $tariff = \App\Models\Tariff::where(function($q) use ($monthEnd) {
                        $q->whereNull('valid_from')
                          ->orWhere('valid_from', '<=', $monthEnd);
                    })
                    ->where(function($q) use ($monthStart) {
                        $q->whereNull('valid_until')
                          ->orWhere('valid_until', '>=', $monthStart);
                    })
                    ->orderBy('valid_from', 'desc')
                    ->first();

                $mBajo = $tariff && $tariff->b1_price_kwh !== null ? (float)$tariff->b1_price_kwh : 0.0;
                $mMedio = $tariff && $tariff->b2_price_kwh !== null ? (float)$tariff->b2_price_kwh : 0.0;
                $mAlto = $tariff && $tariff->b3_price_kwh !== null ? (float)$tariff->b3_price_kwh : 0.0;
            }

            $latestRates = ['bajo' => $mBajo, 'medio' => $mMedio, 'alto' => $mAlto];

            $rBajo = round($kBajo * $mBajo, 2);
            $rMedio = round($kMedio * $mMedio, 2);
            $rAlto = round($kAlto * $mAlto, 2);
            $rTotal = round($rBajo + $rMedio + $rAlto, 2);

            $totalKwhBajo += $kBajo;
            $totalKwhMedio += $kMedio;
            $totalKwhAlto += $kAlto;
            $grandTotalKwh += $kTotal;
            $totalGrossRevenue += $rTotal;

            $resultMonthly[] = [
                'month' => $month,
                'bloque_bajo' => ['kwh' => $kBajo, 'rate_aetn' => $mBajo, 'gross_revenue' => $rBajo],
                'bloque_medio' => ['kwh' => $kMedio, 'rate_aetn' => $mMedio, 'gross_revenue' => $rMedio],
                'bloque_alto' => ['kwh' => $kAlto, 'rate_aetn' => $mAlto, 'gross_revenue' => $rAlto],
                'total_kwh' => $kTotal,
                'total_gross_revenue' => $rTotal,
            ];
        }

        // Use active tariff resolved for the period to get current hour definitions
        $defTariff = $activeTariff ?: (\App\Models\Tariff::where('name', 'LIKE', '%Estándar%')
            ->orWhere('name', 'LIKE', '%Estandar%')
            ->first() ?: \App\Models\Tariff::first());

        // Standardize formats for B1, B2, B3 hours
        $formatTime = function ($timeStr, $default) {
            if (!$timeStr) return $default;
            return substr($timeStr, 0, 5); // Take "HH:MM" from "HH:MM:SS"
        };

        $bajoTime = $defTariff && $defTariff->b1_start && $defTariff->b1_end 
            ? ($formatTime($defTariff->b1_start, '23:00') . ' - ' . $formatTime($defTariff->b1_end, '07:00'))
            : "23:00 - 07:00";

        $altoTime = $defTariff && $defTariff->b3_start && $defTariff->b3_end
            ? ($formatTime($defTariff->b3_start, '18:00') . ' - ' . $formatTime($defTariff->b3_end, '21:00'))
            : "18:00 - 21:00";

        $medioTime = "07:00 - 18:00, 21:00 - 23:00";
        if ($defTariff && $defTariff->b2_start && $defTariff->b2_end) {
            $medioTime = $formatTime($defTariff->b2_start, '07:00') . ' - ' . $formatTime($defTariff->b2_end, '18:00');
            if ($defTariff->b4_start && $defTariff->b4_end) {
                $medioTime .= ', ' . $formatTime($defTariff->b4_start, '21:00') . ' - ' . $formatTime($defTariff->b4_end, '23:00');
            }
        }

        $blockDefinitions = [
            'bajo' => $bajoTime,
            'medio' => $medioTime,
            'alto' => $altoTime,
        ];

        return response()->json([
            'success' => true,
            'has_data' => count($resultMonthly) > 0 && $grandTotalKwh > 0,
            'rates_applied' => $latestRates,
            'summary_totals' => [
                'total_kwh_bajo' => round($totalKwhBajo, 1),
                'total_kwh_medio' => round($totalKwhMedio, 1),
                'total_kwh_alto' => round($totalKwhAlto, 1),
                'grand_total_kwh' => round($grandTotalKwh, 1),
                'grand_total_gross_revenue_bob' => round($totalGrossRevenue, 2),
            ],
            'monthly_details' => $resultMonthly,
            'block_definitions' => $blockDefinitions,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('getAetnBilling Error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Endpoint 3: Operación de Cargas (Fallas, Horarios, Intentos)
     */
    public function getOperations(Request $request)
    {
        $baseQuery = ChargingSession::query()->whereIn('charging_sessions.status', ['Completed', 'Failed']);
        $this->applyFilters($baseQuery, $request);

        $localTimeExpr = 'DATE_SUB(charging_sessions.start_time, INTERVAL 4 HOUR)';

        $dailyAttempts = (clone $baseQuery)
            ->selectRaw("
                DATE($localTimeExpr) as date,
                SUM(CASE WHEN charging_sessions.status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN charging_sessions.status = 'Failed' THEN 1 ELSE 0 END) as failed_count,
                COUNT(charging_sessions.id) as total_attempts
            ")
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

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

        $latestSessions = (clone $baseQuery)
            ->with(['station', 'user'])
            ->leftJoin('connectors', 'charging_sessions.connector_id', '=', 'connectors.id')
            ->select('charging_sessions.*')
            ->selectRaw('IFNULL(NULLIF(connectors.type, ""), "Desconocido") as mapped_connector_type')
            ->orderBy('charging_sessions.start_time', 'desc')
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'transaction_id' => $s->transaction_id,
                    'station_name' => $s->station?->name ?? 'N/A',
                    'user_name' => $s->user?->name ?? 'Anónimo/Card',
                    'status' => $s->status,
                    'start_time' => $s->start_time?->toDateTimeString(),
                    'fecha_local_corregida' => $s->start_time ? $s->start_time->setTimezone('America/La_Paz')->toDateTimeString() : 'N/A',
                    'fecha_ajustada' => true,
                    'stop_time' => $s->stop_time?->toDateTimeString(),
                    'energy_kwh' => (float) $s->total_energy_kwh,
                    'total_cost_bob' => (float) $s->total_cost,
                    'stop_reason' => $s->stop_reason,
                    'connector_type' => $s->mapped_connector_type,
                ];
            });

        return response()->json([
            'success' => true,
            'daily_attempts' => $dailyAttempts,
            'failures_by_station' => $failuresByStation,
            'hourly_heatmap' => $hourlyHeatmap,
            'connector_usage' => $connectorUsage,
            'latest_sessions' => $latestSessions,
        ]);
    }

    /**
     * Endpoint 4: Clientes y Saldos (Recargas vs Consumos)
     */
    public function getClientBalances(Request $request)
    {
        $startDate = $request->query('startDate');
        $endDate = $request->query('endDate');
        $month = $request->query('month');

        $rechargesQuery = DB::table('wallet_transactions')
            ->where('type', 'RECHARGE')
            ->where('status', 'Completed')
            ->whereIn('user_id', function($q) {
                $q->select('id')
                  ->from('users')
                  ->where('is_admin', 0)
                  ->whereNotExists(function($query) {
                      $query->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User')
                            ->where('roles.name', '<>', 'client');
                  })
                  ->whereNotNull('billing_document')
                  ->where('billing_document', '<>', '')
                  ->where('billing_document', '<>', '0')
                  ->where('name', 'NOT LIKE', 'Usuario RFID%')
                  ->where('email', 'NOT LIKE', '%@evce.temp');
            });

        $excludedRechargesQuery = DB::table('wallet_transactions')
            ->where('type', 'RECHARGE')
            ->where('status', 'Completed')
            ->whereIn('user_id', function($q) {
                $q->select('id')
                  ->from('users')
                  ->where('is_admin', 0)
                  ->whereNotExists(function($query) {
                      $query->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User')
                            ->where('roles.name', '<>', 'client');
                  })
                  ->where(function($sub) {
                      $sub->whereNull('billing_document')
                          ->orWhere('billing_document', '')
                          ->orWhere('billing_document', '0')
                          ->orWhere('name', 'LIKE', 'Usuario RFID%')
                          ->orWhere('email', 'LIKE', '%@evce.temp');
                  });
            });

        $refundsQuery = DB::table('wallet_transactions')
            ->whereIn('type', ['REFUND', 'CREDIT'])
            ->whereIn('user_id', function($q) {
                $q->select('id')
                  ->from('users')
                  ->where('is_admin', 0)
                  ->whereNotExists(function($query) {
                      $query->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User')
                            ->where('roles.name', '<>', 'client');
                  })
                  ->whereNotNull('billing_document')
                  ->where('billing_document', '<>', '')
                  ->where('billing_document', '<>', '0')
                  ->where('name', 'NOT LIKE', 'Usuario RFID%')
                  ->where('email', 'NOT LIKE', '%@evce.temp');
            });

        $sessionsQuery = DB::table('charging_sessions')
            ->where('status', 'Completed')
            ->whereIn('user_id', function($q) {
                $q->select('id')
                  ->from('users')
                  ->where('is_admin', 0)
                  ->whereNotExists(function($query) {
                      $query->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User')
                            ->where('roles.name', '<>', 'client');
                  })
                  ->whereNotNull('billing_document')
                  ->where('billing_document', '<>', '')
                  ->where('billing_document', '<>', '0')
                  ->where('name', 'NOT LIKE', 'Usuario RFID%')
                  ->where('email', 'NOT LIKE', '%@evce.temp');
            });

        // Apply date filters
        $localTimeExpr = 'DATE_SUB(created_at, INTERVAL 4 HOUR)';
        if ($month) {
            $carbonMonth = Carbon::parse($month . '-01');
            $rechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                           ->whereRaw("DATE($localTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
            $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                                   ->whereRaw("DATE($localTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
            $refundsQuery->whereRaw("DATE($localTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                         ->whereRaw("DATE($localTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
            
            $sessTimeExpr = 'DATE_SUB(start_time, INTERVAL 4 HOUR)';
            $sessionsQuery->whereRaw("DATE($sessTimeExpr) >= ?", [$carbonMonth->startOfMonth()->toDateString()])
                          ->whereRaw("DATE($sessTimeExpr) <= ?", [$carbonMonth->endOfMonth()->toDateString()]);
        } else {
            if ($startDate) {
                $rechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
                $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
                $refundsQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
                
                $sessTimeExpr = 'DATE_SUB(start_time, INTERVAL 4 HOUR)';
                $sessionsQuery->whereRaw("DATE($sessTimeExpr) >= ?", [$startDate]);
            }
            if ($endDate) {
                $rechargesQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
                $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
                $refundsQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
                
                $sessTimeExpr = 'DATE_SUB(start_time, INTERVAL 4 HOUR)';
                $sessionsQuery->whereRaw("DATE($sessTimeExpr) <= ?", [$endDate]);
            }
        }

        // Calculate KPI totals
        $totalRecharged = $rechargesQuery->sum('amount');
        $totalExcluido = $excludedRechargesQuery->sum('amount');
        $totalChargeConsumed = $sessionsQuery->sum('total_cost');
        $totalRefunds = $refundsQuery->sum('amount');
        $netWalletBalance = $totalRecharged - $totalChargeConsumed + $totalRefunds;

        $localTxTime = 'DATE_SUB(wallet_transactions.created_at, INTERVAL 4 HOUR)';
        $localSessTime = 'DATE_SUB(charging_sessions.start_time, INTERVAL 4 HOUR)';

        // 1. Recharges subquery
        $rechargesSelect = 'user_id';
        $rechargesBindings = [];
        if ($startDate) {
            $rechargesSelect .= ", SUM(CASE WHEN DATE($localTxTime) < ? THEN amount ELSE 0 END) as recharges_before";
            $rechargesBindings[] = $startDate;
        } else {
            $rechargesSelect .= ", 0 as recharges_before";
        }
        if ($startDate && $endDate) {
            $rechargesSelect .= ", SUM(CASE WHEN DATE($localTxTime) >= ? AND DATE($localTxTime) <= ? THEN amount ELSE 0 END) as recharges_during";
            $rechargesBindings[] = $startDate;
            $rechargesBindings[] = $endDate;
        } else if ($startDate) {
            $rechargesSelect .= ", SUM(CASE WHEN DATE($localTxTime) >= ? THEN amount ELSE 0 END) as recharges_during";
            $rechargesBindings[] = $startDate;
        } else if ($endDate) {
            $rechargesSelect .= ", SUM(CASE WHEN DATE($localTxTime) <= ? THEN amount ELSE 0 END) as recharges_during";
            $rechargesBindings[] = $endDate;
        } else {
            $rechargesSelect .= ", SUM(amount) as recharges_during";
        }
        if ($endDate) {
            $rechargesSelect .= ", SUM(CASE WHEN DATE($localTxTime) > ? THEN amount ELSE 0 END) as recharges_after";
            $rechargesBindings[] = $endDate;
        } else {
            $rechargesSelect .= ", 0 as recharges_after";
        }

        $rechargesSub = DB::table('wallet_transactions')
            ->selectRaw($rechargesSelect, $rechargesBindings)
            ->where('type', 'RECHARGE')
            ->where('status', 'Completed')
            ->where(function($q) {
                $q->where('reference_id', 'LIKE', 'TAG-RECH-%')
                  ->orWhere('reference', 'LIKE', 'TAG-RECH-%');
            })
            ->groupBy('user_id');

        // 2. Refunds subquery
        $refundsSelect = 'wallet_transactions.user_id';
        $refundsBindings = [];
        if ($startDate) {
            $refundsSelect .= ", SUM(CASE WHEN DATE($localTxTime) < ? THEN wallet_transactions.amount ELSE 0 END) as refunds_before";
            $refundsBindings[] = $startDate;
        } else {
            $refundsSelect .= ", 0 as refunds_before";
        }
        if ($startDate && $endDate) {
            $refundsSelect .= ", SUM(CASE WHEN DATE($localTxTime) >= ? AND DATE($localTxTime) <= ? THEN wallet_transactions.amount ELSE 0 END) as refunds_during";
            $refundsBindings[] = $startDate;
            $refundsBindings[] = $endDate;
        } else if ($startDate) {
            $refundsSelect .= ", SUM(CASE WHEN DATE($localTxTime) >= ? THEN wallet_transactions.amount ELSE 0 END) as refunds_during";
            $refundsBindings[] = $startDate;
        } else if ($endDate) {
            $refundsSelect .= ", SUM(CASE WHEN DATE($localTxTime) <= ? THEN wallet_transactions.amount ELSE 0 END) as refunds_during";
            $refundsBindings[] = $endDate;
        } else {
            $refundsSelect .= ", SUM(wallet_transactions.amount) as refunds_during";
        }
        if ($endDate) {
            $refundsSelect .= ", SUM(CASE WHEN DATE($localTxTime) > ? THEN wallet_transactions.amount ELSE 0 END) as refunds_after";
            $refundsBindings[] = $endDate;
        } else {
            $refundsSelect .= ", 0 as refunds_after";
        }

        $refundsSub = DB::table('wallet_transactions')
            ->leftJoin('charging_sessions', 'wallet_transactions.reference_id', '=', 'charging_sessions.transaction_id')
            ->leftJoin('rfid_tags', 'charging_sessions.rfid_tag_id', '=', 'rfid_tags.id')
            ->selectRaw($refundsSelect, $refundsBindings)
            ->whereIn('wallet_transactions.type', ['REFUND', 'CREDIT'])
            ->where(function($q) {
                $q->where(function($sub) {
                    $sub->whereNotNull('charging_sessions.rfid_tag_id')
                        ->where('rfid_tags.is_virtual', 0);
                })
                ->orWhere('wallet_transactions.description', 'LIKE', '%(Tarjeta:%');
            })
            ->groupBy('wallet_transactions.user_id');

        // 3. Sessions (Consumption) subquery
        $sessionsSelect = 'charging_sessions.user_id';
        $sessionsBindings = [];
        if ($startDate) {
            $sessionsSelect .= ", SUM(CASE WHEN DATE($localSessTime) < ? THEN total_cost ELSE 0 END) as consumption_before";
            $sessionsBindings[] = $startDate;
        } else {
            $sessionsSelect .= ", 0 as consumption_before";
        }
        if ($startDate && $endDate) {
            $sessionsSelect .= ", SUM(CASE WHEN DATE($localSessTime) >= ? AND DATE($localSessTime) <= ? THEN total_cost ELSE 0 END) as consumption_during";
            $sessionsBindings[] = $startDate;
            $sessionsBindings[] = $endDate;
        } else if ($startDate) {
            $sessionsSelect .= ", SUM(CASE WHEN DATE($localSessTime) >= ? THEN total_cost ELSE 0 END) as consumption_during";
            $sessionsBindings[] = $startDate;
        } else if ($endDate) {
            $sessionsSelect .= ", SUM(CASE WHEN DATE($localSessTime) <= ? THEN total_cost ELSE 0 END) as consumption_during";
            $sessionsBindings[] = $endDate;
        } else {
            $sessionsSelect .= ", SUM(total_cost) as consumption_during";
        }
        if ($endDate) {
            $sessionsSelect .= ", SUM(CASE WHEN DATE($localSessTime) > ? THEN total_cost ELSE 0 END) as consumption_after";
            $sessionsBindings[] = $endDate;
        } else {
            $sessionsSelect .= ", 0 as consumption_after";
        }

        $sessionsSub = DB::table('charging_sessions')
            ->join('rfid_tags', 'charging_sessions.rfid_tag_id', '=', 'rfid_tags.id')
            ->selectRaw($sessionsSelect, $sessionsBindings)
            ->where('rfid_tags.is_virtual', 0)
            ->where('charging_sessions.status', 'Completed')
            ->groupBy('charging_sessions.user_id');

        // Legacy general tables subqueries
        $legacySessionsSub = $sessionsQuery
            ->selectRaw('user_id, COUNT(id) as sessions_count, SUM(total_energy_kwh) as total_energy_kwh, SUM(total_cost) as total_spent_bob')
            ->groupBy('user_id');

        $legacyRechargesSub = $rechargesQuery
            ->selectRaw('user_id, SUM(amount) as total_recharged_bob')
            ->groupBy('user_id');

        $legacyRefundsSub = $refundsQuery
            ->selectRaw('user_id, SUM(amount) as total_refunds_bob')
            ->groupBy('user_id');

        $rechargesGenSub = DB::table('wallet_transactions')
            ->selectRaw($rechargesSelect, $rechargesBindings)
            ->where('type', 'RECHARGE')
            ->where('status', 'Completed')
            ->groupBy('user_id');

        $refundsGenSub = DB::table('wallet_transactions')
            ->selectRaw($refundsSelect, $refundsBindings)
            ->whereIn('type', ['REFUND', 'CREDIT'])
            ->groupBy('user_id');

        $rfidSub = DB::table('rfid_tags')
            ->selectRaw('
                user_id, 
                SUM(CASE WHEN is_virtual = 0 THEN balance ELSE 0 END) as rfid_balance_bob,
                SUM(CASE WHEN is_virtual = 0 THEN 1 ELSE 0 END) as physical_tags_count,
                SUM(CASE WHEN is_virtual = 1 THEN 1 ELSE 0 END) as virtual_tags_count
            ')
            ->groupBy('user_id');

        $mapUserBalances = function ($c) {
            $is_temp_rfid_user = (str_starts_with($c->client_name, 'Usuario RFID') || str_contains($c->client_email, '@evce.temp'));

            if ($is_temp_rfid_user) {
                $rfid_recharges_before = (float) $c->gen_recharges_before;
                $rfid_recharges_during = (float) $c->gen_recharges_during;
                $rfid_recharges_after = (float) $c->gen_recharges_after;

                $rfid_refunds_before = (float) $c->gen_refunds_before;
                $rfid_refunds_during = (float) $c->gen_refunds_during;
                $rfid_refunds_after = (float) $c->gen_refunds_after;
            } else {
                $rfid_recharges_before = (float) $c->rfid_recharges_before;
                $rfid_recharges_during = (float) $c->rfid_recharges_during;
                $rfid_recharges_after = (float) $c->rfid_recharges_after;

                $rfid_refunds_before = (float) $c->rfid_refunds_before;
                $rfid_refunds_during = (float) $c->rfid_refunds_during;
                $rfid_refunds_after = (float) $c->rfid_refunds_after;
            }

            $rfid_consumption_before = (float) $c->rfid_consumption_before;
            $rfid_consumption_during = (float) $c->rfid_consumption_during;
            $rfid_consumption_after = (float) $c->rfid_consumption_after;

            $rfid_current_balance = (float) $c->rfid_current_balance;

            // Reconstruct opening balance
            $rfid_opening_balance = $rfid_recharges_before + $rfid_refunds_before - $rfid_consumption_before;
            if ($rfid_opening_balance < 0) {
                $rfid_opening_balance = 0.00;
            }
            
            // Determine if there is pre-existing historical balance not tracked by transactions
            $total_lifetime_transactions = ($rfid_recharges_before + $rfid_recharges_during + $rfid_recharges_after) +
                                            ($rfid_refunds_before + $rfid_refunds_during + $rfid_refunds_after) -
                                            ($rfid_consumption_before + $rfid_consumption_during + $rfid_consumption_after);
            
            $historical_discrepancy = abs($rfid_current_balance - $total_lifetime_transactions);
            $has_untraced_history = $historical_discrepancy > 2.0; // 2 BOB tolerance
            
            $audit_basis = 'complete';
            if ($has_untraced_history) {
                $audit_basis = 'historical_balance_missing';
            }

            $rfid_expected_closing_balance = $rfid_opening_balance + $rfid_recharges_during + $rfid_refunds_during - $rfid_consumption_during;
            $rfid_closing_balance = $rfid_current_balance - ($rfid_recharges_after + $rfid_refunds_after - $rfid_consumption_after);
            if ($rfid_closing_balance < 0) {
                $rfid_closing_balance = 0.00;
            }

            $is_corporate_client = ($c->company_id !== null);

            return [
                'user_id' => $c->user_id,
                'client_name' => $c->client_name,
                'client_email' => $c->client_email,
                'is_corporate_client' => $is_corporate_client,
                'is_temp_rfid_user' => $is_temp_rfid_user,
                'sessions_count' => (int) $c->sessions_count,
                'total_energy_kwh' => round((float) $c->total_energy_kwh, 1),
                'total_spent_bob' => round((float) $c->total_spent_bob, 2),
                'total_recharged_bob' => round((float) $c->total_recharged_bob, 2),
                'total_refunds_bob' => round((float) $c->total_refunds_bob, 2),
                'rfid_balance_bob' => round($rfid_current_balance, 2),
                'physical_tags_count' => (int) $c->physical_tags_count,
                'virtual_tags_count' => (int) $c->virtual_tags_count,
                'app_balance_bob' => round((float) $c->app_balance_bob, 2),
                
                // Reconciliation fields
                'rfid_opening_balance' => round($rfid_opening_balance, 2),
                'rfid_recharges_period' => round($rfid_recharges_during, 2),
                'rfid_refunds_period' => round($rfid_refunds_during, 2),
                'rfid_consumption_period' => round($rfid_consumption_during, 2),
                'rfid_closing_balance' => round($rfid_closing_balance, 2),
                'rfid_expected_closing_balance' => round($rfid_expected_closing_balance, 2),
                'audit_basis' => $audit_basis,
            ];
        };

        // Query all users (Base Query Builder)
        $baseQueryBuilder = DB::table('users')
            ->where('users.is_admin', 0)
            ->whereNotExists(function($query) {
                $query->select(DB::raw(1))
                      ->from('model_has_roles')
                      ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                      ->whereColumn('model_has_roles.model_id', 'users.id')
                      ->where('model_has_roles.model_type', 'App\\Models\\User')
                      ->where('roles.name', '<>', 'client');
            })
            ->leftJoin('wallets', 'users.id', '=', 'wallets.user_id')
            ->leftJoinSub($legacySessionsSub, 'sessions', 'users.id', '=', 'sessions.user_id')
            ->leftJoinSub($legacyRechargesSub, 'recharges', 'users.id', '=', 'recharges.user_id')
            ->leftJoinSub($legacyRefundsSub, 'refunds', 'users.id', '=', 'refunds.user_id')
            ->leftJoinSub($rechargesSub, 'rech', 'users.id', '=', 'rech.user_id')
            ->leftJoinSub($refundsSub, 'ref', 'users.id', '=', 'ref.user_id')
            ->leftJoinSub($rechargesGenSub, 'rech_gen', 'users.id', '=', 'rech_gen.user_id')
            ->leftJoinSub($refundsGenSub, 'ref_gen', 'users.id', '=', 'ref_gen.user_id')
            ->leftJoinSub($sessionsSub, 'sess', 'users.id', '=', 'sess.user_id')
            ->leftJoinSub($rfidSub, 'rfids', 'users.id', '=', 'rfids.user_id')
            ->selectRaw('
                users.id as user_id,
                users.name as client_name,
                users.email as client_email,
                users.company_id,
                COALESCE(sessions.sessions_count, 0) as sessions_count,
                COALESCE(sessions.total_energy_kwh, 0) as total_energy_kwh,
                COALESCE(sessions.total_spent_bob, 0) as total_spent_bob,
                COALESCE(recharges.total_recharged_bob, 0) as total_recharged_bob,
                COALESCE(refunds.total_refunds_bob, 0) as total_refunds_bob,
                COALESCE(rfids.rfid_balance_bob, 0) as rfid_current_balance,
                COALESCE(rfids.physical_tags_count, 0) as physical_tags_count,
                COALESCE(rfids.virtual_tags_count, 0) as virtual_tags_count,
                COALESCE(wallets.balance, 0) as app_balance_bob,
                
                COALESCE(rech.recharges_before, 0) as rfid_recharges_before,
                COALESCE(rech.recharges_during, 0) as rfid_recharges_during,
                COALESCE(rech.recharges_after, 0) as rfid_recharges_after,
                
                COALESCE(ref.refunds_before, 0) as rfid_refunds_before,
                COALESCE(ref.refunds_during, 0) as rfid_refunds_during,
                COALESCE(ref.refunds_after, 0) as rfid_refunds_after,
                
                COALESCE(rech_gen.recharges_before, 0) as gen_recharges_before,
                COALESCE(rech_gen.recharges_during, 0) as gen_recharges_during,
                COALESCE(rech_gen.recharges_after, 0) as gen_recharges_after,
                
                COALESCE(ref_gen.refunds_before, 0) as gen_refunds_before,
                COALESCE(ref_gen.refunds_during, 0) as gen_refunds_during,
                COALESCE(ref_gen.refunds_after, 0) as gen_refunds_after,
                
                COALESCE(sess.consumption_before, 0) as rfid_consumption_before,
                COALESCE(sess.consumption_during, 0) as rfid_consumption_during,
                COALESCE(sess.consumption_after, 0) as rfid_consumption_after
            ');

        $topConsumersQuery = (clone $baseQueryBuilder)
            ->whereNotNull('users.billing_document')
            ->where('users.billing_document', '<>', '')
            ->where('users.billing_document', '<>', '0')
            ->where('users.name', 'NOT LIKE', 'Usuario RFID%')
            ->where('users.email', 'NOT LIKE', '%@evce.temp');

        $excludedConsumersQuery = (clone $baseQueryBuilder)
            ->where(function($q) {
                $q->whereNull('users.billing_document')
                  ->orWhere('users.billing_document', '')
                  ->orWhere('users.billing_document', '0')
                  ->orWhere('users.name', 'LIKE', 'Usuario RFID%')
                  ->orWhere('users.email', 'LIKE', '%@evce.temp');
            });

        $topConsumers = $topConsumersQuery->get()->map($mapUserBalances);
        $excludedConsumers = $excludedConsumersQuery->get()->map($mapUserBalances);

        return response()->json([
            'success' => true,
            'balance_summary' => [
                'total_recharged_bob' => round((float) $totalRecharged, 2),
                'total_excluido_bob' => round((float) $totalExcluido, 2),
                'total_charge_consumed_bob' => round((float) $totalChargeConsumed, 2),
                'total_refunds_bob' => round((float) $totalRefunds, 2),
                'net_wallet_balance_bob' => round((float) $netWalletBalance, 2),
            ],
            'top_consuming_clients' => $topConsumers,
            'excluded_clients' => $excludedConsumers,
        ]);
    }

    /**
     * Endpoint 5: RFID / Concesionarias (JAC, XPENG, CHANGAN, MG)
     */
    public function getRfidDealerships(Request $request)
    {
        $cardType = $request->query('cardType', 'physical');
        $companyId = $request->query('companyId');

        $tagsQuery = RfidTag::with(['user.vehicles', 'company']);
        if ($cardType === 'physical') {
            $tagsQuery->where('is_virtual', false);
        } elseif ($cardType === 'virtual') {
            $tagsQuery->where('is_virtual', true);
        }

        if ($companyId) {
            $tagsQuery->where('company_id', $companyId);
        }

        $allTags = $tagsQuery->get();
        $totalCards = $allTags->count();
        $usedTagIds = ChargingSession::whereNotNull('rfid_tag_id')->distinct()->pluck('rfid_tag_id')->toArray();
        
        $usedCardsCount = count(array_intersect($allTags->pluck('id')->toArray(), $usedTagIds));
        $unusedCardsCount = $totalCards - $usedCardsCount;

        $rfidStats = ChargingSession::whereNotNull('rfid_tag_id')
            ->selectRaw('
                rfid_tag_id,
                COUNT(id) as sessions_count,
                SUM(total_energy_kwh) as energy_kwh,
                SUM(total_cost) as revenue_bob
            ')
            ->groupBy('rfid_tag_id')
            ->get()
            ->keyBy('rfid_tag_id');

        $totalCreditConsumed = $rfidStats->sum('revenue_bob');
        $totalEnergyConsumed = $rfidStats->sum('energy_kwh');

        $targetDealerships = ['JAC', 'XPENG', 'CHANGAN', 'MG'];
        $dealershipSummary = [];

        foreach ($targetDealerships as $brand) {
            // Find company by name
            $company = Company::where('name', 'LIKE', '%' . $brand . '%')->first();
            if ($company) {
                $brandTags = $allTags->where('company_id', $company->id);
            } else {
                $brandTags = collect();
            }
            $brandTagIds = $brandTags->pluck('id')->toArray();

            $sessionsForBrand = ChargingSession::whereIn('rfid_tag_id', $brandTagIds);

            $dealershipSummary[] = [
                'brand' => $brand,
                'cards_delivered' => $brandTags->count(),
                'cards_active' => count(array_intersect($brandTagIds, $usedTagIds)),
                'sessions_count' => (clone $sessionsForBrand)->count(),
                'energy_kwh' => round((float) (clone $sessionsForBrand)->sum('total_energy_kwh'), 1),
                'revenue_bob' => round((float) (clone $sessionsForBrand)->sum('total_cost'), 2),
            ];
        }

        $userWalletRecharges = \App\Models\WalletTransaction::where('type', 'RECHARGE')
            ->whereIn('status', ['COMPLETED', 'Completed'])
            ->selectRaw('user_id, SUM(amount) as total_charged')
            ->groupBy('user_id')
            ->pluck('total_charged', 'user_id');

        $topRfidDetails = $allTags
            ->map(function ($tag) use ($rfidStats, $userWalletRecharges) {
                $stat = $rfidStats->get($tag->id);
                
                // Get vehicle brand(s) of user
                $brands = 'N/A';
                if ($tag->user && $tag->user->vehicles) {
                    $brands = $tag->user->vehicles->pluck('brand')->unique()->implode(', ');
                }
                if (empty($brands)) {
                    $brands = 'N/A';
                }
                
                // Get the last session date for this RFID
                $lastSessionDate = ChargingSession::where('rfid_tag_id', $tag->id)
                    ->orderBy('start_time', 'desc')
                    ->value('start_time');
                
                // Format the local date for the last session
                $lastSessionLocal = $lastSessionDate ? Carbon::parse($lastSessionDate)->setTimezone('America/La_Paz')->toDateTimeString() : 'Nunca';

                $totalCharged = 0.0;
                if ($tag->is_virtual) {
                    $totalCharged = (float) ($tag->user_id ? ($userWalletRecharges->get($tag->user_id) ?? 0) : 0);
                } else {
                    $totalCharged = (float) $tag->balance + (float) ($stat ? ($stat->revenue_bob ?? 0) : 0);
                }

                return [
                    'id' => $tag->id,
                    'tag_code' => $tag->tag_code,
                    'user_name' => $tag->user?->name ?? 'Sin Asignar',
                    'brand' => $brands,
                    'company_name' => $tag->company?->name ?? 'Particular',
                    'is_virtual' => (bool) $tag->is_virtual,
                    'sessions_count' => $stat ? ($stat->sessions_count ?? 0) : 0,
                    'energy_kwh' => round((float) ($stat ? ($stat->energy_kwh ?? 0) : 0), 1),
                    'revenue_bob' => round((float) ($stat ? ($stat->revenue_bob ?? 0) : 0), 2),
                    'current_balance' => (float) $tag->balance,
                    'total_charged' => round($totalCharged, 2),
                    'last_session_time' => $lastSessionLocal,
                    'fecha_ajustada' => $lastSessionDate ? true : false,
                ];
            })
            ->sortByDesc('sessions_count')
            ->values();

        return response()->json([
            'success' => true,
            'rfid_overview' => [
                'total_cards' => $totalCards,
                'used_cards' => $usedCardsCount,
                'unused_cards' => $unusedCardsCount,
                'total_credit_consumed_bob' => round((float) $totalCreditConsumed, 2),
                'total_energy_consumed_kwh' => round((float) $totalEnergyConsumed, 1),
            ],
            'dealerships_breakdown' => $dealershipSummary,
            'top_rfid_tags' => $topRfidDetails,
        ]);
    }

    /**
     * Endpoint 6: Vehículos y Análisis de Brecha
     */
    public function getVehicleReports(Request $request)
    {
        $vehicleBrand = $request->query('vehicleBrand');
        $clientId = $request->query('clientId');

        // Apply filters to vehicles query
        $vehicleQuery = Vehicle::query();
        if ($vehicleBrand) {
            $vehicleQuery->where('brand', 'LIKE', '%' . $vehicleBrand . '%');
        }
        if ($clientId) {
            $vehicleQuery->where('user_id', $clientId);
        }

        // Apply filters to users query
        $clientQuery = User::query();
        if ($vehicleBrand) {
            $clientQuery->whereHas('vehicles', function ($q) use ($vehicleBrand) {
                $q->where('brand', 'LIKE', '%' . $vehicleBrand . '%');
            });
        }
        if ($clientId) {
            $clientQuery->where('id', $clientId);
        }

        $totalVehicles = $vehicleQuery->count();
        $totalClients = $clientQuery->count();
        $avgVehiclesPerClient = $totalClients > 0 ? round($totalVehicles / $totalClients, 2) : 0;

        $topBrandsQuery = Vehicle::selectRaw('brand, COUNT(*) as count')
            ->groupBy('brand')
            ->orderByDesc('count')
            ->limit(10);
        if ($vehicleBrand) {
            $topBrandsQuery->where('brand', 'LIKE', '%' . $vehicleBrand . '%');
        }
        if ($clientId) {
            $topBrandsQuery->where('user_id', $clientId);
        }
        $topBrands = $topBrandsQuery->get();

        $topModelsQuery = Vehicle::selectRaw('CONCAT(brand, " ", model) as full_model, COUNT(*) as count')
            ->groupBy('full_model')
            ->orderByDesc('count')
            ->limit(10);
        if ($vehicleBrand) {
            $topModelsQuery->where('brand', 'LIKE', '%' . $vehicleBrand . '%');
        }
        if ($clientId) {
            $topModelsQuery->where('user_id', $clientId);
        }
        $topModels = $topModelsQuery->get();

        $usersWithVehicles = (clone $vehicleQuery)->whereNotNull('user_id')->distinct()->pluck('user_id')->toArray();

        // Apply filters to charging sessions
        $sessionsQuery = ChargingSession::query();
        $this->applyFilters($sessionsQuery, $request);
        $usersWithSessions = $sessionsQuery->distinct()->pluck('user_id')->toArray();

        $vehicleNoChargeUserIds = array_diff($usersWithVehicles, $usersWithSessions);
        $clientsWithVehicleNoCharges = User::whereIn('id', $vehicleNoChargeUserIds)
            ->select('id', 'name', 'email')
            ->limit(20)
            ->get();

        $chargeNoVehicleUserIds = array_diff($usersWithSessions, $usersWithVehicles);
        $clientsWithChargeNoVehicle = User::whereIn('id', array_filter($chargeNoVehicleUserIds))
            ->select('id', 'name', 'email')
            ->limit(20)
            ->get();

        // Plates distribution query
        $platesPerUser = DB::table('vehicles')
            ->whereNotNull('user_id');
        if ($vehicleBrand) {
            $platesPerUser->where('brand', 'LIKE', '%' . $vehicleBrand . '%');
        }
        if ($clientId) {
            $platesPerUser->where('user_id', $clientId);
        }
        $platesPerUser = $platesPerUser->selectRaw('user_id, COUNT(*) as count')
            ->groupBy('user_id');

        $platesDistributionRaw = DB::query()
            ->fromSub($platesPerUser, 't')
            ->selectRaw('
                SUM(CASE WHEN count = 1 THEN 1 ELSE 0 END) as single_vehicle_users,
                SUM(CASE WHEN count = 2 THEN 1 ELSE 0 END) as double_vehicle_users,
                SUM(CASE WHEN count >= 3 THEN 1 ELSE 0 END) as multi_vehicle_users
            ')
            ->first();

        $platesDistribution = [
            '1_vehicle' => (int)($platesDistributionRaw->single_vehicle_users ?? 0),
            '2_vehicles' => (int)($platesDistributionRaw->double_vehicle_users ?? 0),
            '3_or_more_vehicles' => (int)($platesDistributionRaw->multi_vehicle_users ?? 0)
        ];

        // Top clients with most registered plates
        $topClientsByPlatesQuery = DB::table('vehicles')
            ->whereNotNull('vehicles.user_id')
            ->leftJoin('users', 'vehicles.user_id', '=', 'users.id');
        if ($vehicleBrand) {
            $topClientsByPlatesQuery->where('vehicles.brand', 'LIKE', '%' . $vehicleBrand . '%');
        }
        if ($clientId) {
            $topClientsByPlatesQuery->where('vehicles.user_id', $clientId);
        }
        $topClientsByPlates = $topClientsByPlatesQuery->selectRaw('users.id as user_id, users.name as client_name, users.email as client_email, COUNT(vehicles.id) as vehicle_count')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('vehicle_count')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'metrics' => [
                'total_registered_vehicles' => $totalVehicles,
                'total_registered_clients' => $totalClients,
                'avg_vehicles_per_client' => $avgVehiclesPerClient,
                'clients_with_vehicle_no_charges_count' => count($vehicleNoChargeUserIds),
                'clients_with_charges_no_vehicle_count' => count(array_filter($chargeNoVehicleUserIds)),
            ],
            'top_brands' => $topBrands,
            'top_models' => $topModels,
            'plates_distribution' => $platesDistribution,
            'top_clients_by_plates' => $topClientsByPlates,
            'gap_analysis' => [
                'clients_with_vehicle_no_charges' => $clientsWithVehicleNoCharges,
                'clients_with_charges_no_vehicle' => $clientsWithChargeNoVehicle,
            ]
        ]);
    }
}

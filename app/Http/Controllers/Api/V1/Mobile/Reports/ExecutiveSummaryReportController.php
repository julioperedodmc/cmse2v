<?php

namespace App\Http\Controllers\Api\V1\Mobile\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Mobile\Reports\Traits\HasReportFilters;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecutiveSummaryReportController extends Controller
{
    use HasReportFilters;

    /**
     * Endpoint: Resumen Ejecutivo Principal
     */
    public function getExecutiveSummary(Request $request)
    {
        $cacheKey = 'report_exec_summary_' . md5(json_encode($request->all()));

        $response = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($request) {
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
            ->whereIn('user_id', function ($q) {
                $q->select('id')
                    ->from('users')
                    ->where('is_admin', 0)
                    ->whereNotExists(function ($query) {
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
            ->whereIn('user_id', function ($q) {
                $q->select('id')
                    ->from('users')
                    ->where('is_admin', 0)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', 'App\\Models\\User')
                            ->where('roles.name', '<>', 'client');
                    })
                    ->where(function ($sub) {
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

            return [
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
            ];
        });

        return response()->json($response);
    }
}

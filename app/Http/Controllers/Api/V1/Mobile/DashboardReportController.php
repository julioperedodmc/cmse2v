<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardReportController extends Controller
{
    public function getSummary(Request $request)
    {
        // 1. Obtener filtros opcionales
        $startDate = $request->query('startDate', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('endDate', Carbon::now()->endOfMonth()->toDateString());
        $stationId = $request->query('stationId');

        // 2. Obtener lista de estaciones para los filtros de la app cliente
        $stations = Station::select('id', 'name')->get();

        // 3. Construir la consulta base de sesiones completadas
        $query = ChargingSession::query()->where('charging_sessions.status', 'Completed');

        if ($startDate) {
            $query->whereDate('charging_sessions.start_time', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('charging_sessions.start_time', '<=', $endDate);
        }
        if ($stationId) {
            $query->where('charging_sessions.station_id', $stationId);
        }

        // 4. KPIs
        $kpiQuery = clone $query;
        $kpiData = $kpiQuery->selectRaw('
            COUNT(*) as total_sessions,
            SUM(total_energy_kwh) as total_energy,
            SUM(total_cost) as total_revenue,
            AVG(TIMESTAMPDIFF(MINUTE, start_time, stop_time)) as avg_duration
        ')->first();

        $uniqueUsers = (clone $query)->distinct('user_id')->count('user_id');

        $kpis = [
            'total_sessions' => $kpiData->total_sessions ?? 0,
            'total_energy' => round($kpiData->total_energy ?? 0, 1),
            'total_revenue' => round($kpiData->total_revenue ?? 0, 2),
            'avg_duration' => round($kpiData->avg_duration ?? 0, 0),
            'unique_users' => $uniqueUsers,
        ];

        // 5. Marcas de Vehículos (Top 10)
        $brandQuery = clone $query;
        $brands = $brandQuery->selectRaw('
            IFNULL(
                NULLIF(charging_sessions.vehicle_brand, ""),
                IFNULL(
                    (SELECT brand FROM vehicles WHERE vehicles.user_id = charging_sessions.user_id LIMIT 1),
                    "No Registrado"
                )
            ) as brand,
            COUNT(*) as sessions_count,
            SUM(total_energy_kwh) as energy_sum
        ')
        ->groupBy('brand')
        ->orderByDesc('sessions_count')
        ->limit(10)
        ->get()
        ->toArray();

        // 6. Clientes Recurrentes (Top 10)
        $clientQuery = clone $query;
        $recurrentClients = $clientQuery->join('users', 'charging_sessions.user_id', '=', 'users.id')
            ->selectRaw('
                users.name as client_name,
                users.email as client_email,
                COUNT(charging_sessions.id) as sessions_count,
                SUM(charging_sessions.total_cost) as revenue_sum,
                SUM(charging_sessions.total_energy_kwh) as energy_sum
            ')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('sessions_count')
            ->limit(10)
            ->get()
            ->toArray();

        // 7. Horas Pico (Top 8 ordenadas por número de sesiones)
        $hourQuery = clone $query;
        $hourlyUsage = $hourQuery->selectRaw('HOUR(charging_sessions.start_time) as hour, COUNT(*) as sessions_count')
            ->groupBy('hour')
            ->orderByDesc('sessions_count')
            ->limit(8)
            ->get()
            ->toArray();

        $peakHour = !empty($hourlyUsage) ? (int)$hourlyUsage[0]['hour'] : null;

        // 8. Días de la semana más concurridos
        $dayQuery = clone $query;
        $dailyData = $dayQuery->selectRaw('DAYOFWEEK(charging_sessions.start_time) as day_of_week, COUNT(*) as sessions_count')
            ->groupBy('day_of_week')
            ->orderBy('day_of_week')
            ->pluck('sessions_count', 'day_of_week')
            ->toArray();

        $daysMap = [
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
            1 => 'Domingo',
        ];

        $dailyUsage = [];
        $maxDayVal = -1;
        $peakDay = null;

        foreach ($daysMap as $num => $name) {
            $count = $dailyData[$num] ?? 0;
            $dailyUsage[$name] = $count;
            if ($count > $maxDayVal) {
                $maxDayVal = $count;
                $peakDay = $name;
            }
        }

        // 9. Uso por Tipo de Conector
        $connectorQuery = clone $query;
        $connectorUsage = $connectorQuery
            ->leftJoin('connectors', 'charging_sessions.connector_id', '=', 'connectors.id')
            ->selectRaw('
                IFNULL(NULLIF(connectors.type, ""), "Desconocido") as connector_type,
                COUNT(charging_sessions.id) as sessions_count,
                ROUND(SUM(charging_sessions.total_energy_kwh), 1) as energy_sum,
                ROUND(SUM(charging_sessions.total_cost), 2) as revenue_sum
            ')
            ->groupBy('connectors.type')
            ->orderByDesc('sessions_count')
            ->get()
            ->toArray();

        // 10. Desempeño por Estación (Breakdown por estación cuando se selecciona "Todas")
        $stationPerformance = [];
        if (!$stationId) {
            $stationPerformanceQuery = clone $query;
            $stationPerformance = $stationPerformanceQuery
                ->leftJoin('stations', 'charging_sessions.station_id', '=', 'stations.id')
                ->selectRaw('
                    stations.id as station_id,
                    IFNULL(stations.name, "Estación Eliminada") as station_name,
                    COUNT(charging_sessions.id) as sessions_count,
                    ROUND(SUM(charging_sessions.total_energy_kwh), 1) as energy_sum,
                    ROUND(SUM(charging_sessions.total_cost), 2) as revenue_sum
                ')
                ->groupBy('stations.id', 'stations.name')
                ->orderByDesc('sessions_count')
                ->get()
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'filters' => [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'stationId' => $stationId,
            ],
            'stations' => $stations,
            'data' => [
                'kpis' => $kpis,
                'brands' => $brands,
                'recurrentClients' => $recurrentClients,
                'hourlyUsage' => $hourlyUsage,
                'dailyUsage' => $dailyUsage,
                'peakHour' => $peakHour,
                'peakDay' => $maxDayVal > 0 ? $peakDay : null,
                'connectorUsage' => $connectorUsage,
                'stationPerformance' => $stationPerformance,
            ]
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Mobile\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Mobile\Reports\Traits\HasReportFilters;
use App\Models\ChargingSession;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleReportController extends Controller
{
    use HasReportFilters;

    /**
     * Endpoint: Vehículos y Análisis de Brecha
     */
    public function getVehicleReports(Request $request)
    {
        $cacheKey = 'report_vehicles_' . md5(json_encode($request->all()));

        $response = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(10), function () use ($request) {
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
            '1_vehicle' => (int) ($platesDistributionRaw->single_vehicle_users ?? 0),
            '2_vehicles' => (int) ($platesDistributionRaw->double_vehicle_users ?? 0),
            '3_or_more_vehicles' => (int) ($platesDistributionRaw->multi_vehicle_users ?? 0)
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

        // =========================================================================
        // 6. ANÁLISIS DE TAMAÑO DE BATERÍAS DE LOS VEHÍCULOS
        // =========================================================================
        $possibleBatteryCols = ['battery_capacity', 'battery_capacity_kwh', 'battery_kwh', 'battery_size', 'battery', 'capacity', 'capacidad_bateria'];

        $vehiclesList = (clone $vehicleQuery)->get();

        $batteryRanges = [
            '< 30 kWh' => 0,
            '30 - 50 kWh' => 0,
            '50 - 70 kWh' => 0,
            '70 - 90 kWh' => 0,
            '> 90 kWh' => 0,
        ];
        $unspecifiedBattery = 0;
        $validBatteries = [];
        $brandBatteries = [];
        $modelBatteries = [];

        foreach ($vehiclesList as $v) {
            $rawVal = null;
            foreach ($possibleBatteryCols as $c) {
                if (isset($v->{$c}) && $v->{$c} !== null && $v->{$c} !== '') {
                    $rawVal = $v->{$c};
                    break;
                }
            }

            $numericVal = null;
            if ($rawVal !== null && $rawVal !== '') {
                if (is_numeric($rawVal)) {
                    $numericVal = (float) $rawVal;
                } elseif (is_string($rawVal) && preg_match('/([0-9]+(?:\.[0-9]+)?)/', $rawVal, $matches)) {
                    $numericVal = (float) $matches[1];
                }
            }

            if ($numericVal !== null && $numericVal > 0) {
                // Si la capacidad fue ingresada en Wh (ej. 54000 Wh), normalizar a kWh
                if ($numericVal > 1000) {
                    $numericVal = round($numericVal / 1000, 2);
                }

                $validBatteries[] = $numericVal;

                if ($numericVal < 30) {
                    $batteryRanges['< 30 kWh']++;
                } elseif ($numericVal < 50) {
                    $batteryRanges['30 - 50 kWh']++;
                } elseif ($numericVal < 70) {
                    $batteryRanges['50 - 70 kWh']++;
                } elseif ($numericVal < 90) {
                    $batteryRanges['70 - 90 kWh']++;
                } else {
                    $batteryRanges['> 90 kWh']++;
                }

                $brand = !empty($v->brand) ? trim($v->brand) : 'Desconocido';
                if (!isset($brandBatteries[$brand])) {
                    $brandBatteries[$brand] = ['total' => 0, 'count' => 0];
                }
                $brandBatteries[$brand]['total'] += $numericVal;
                $brandBatteries[$brand]['count']++;

                $model = trim(($v->brand ?? '') . ' ' . ($v->model ?? ''));
                if (empty(trim($model))) {
                    $model = 'Modelo No Especificado';
                }
                if (!isset($modelBatteries[$model])) {
                    $modelBatteries[$model] = ['total' => 0, 'count' => 0];
                }
                $modelBatteries[$model]['total'] += $numericVal;
                $modelBatteries[$model]['count']++;
            } else {
                $unspecifiedBattery++;
            }
        }

        // Estructura lista para gráficos de distribución
        $totalEvaluated = count($validBatteries);
        $batteryDistributionChart = [];
        foreach ($batteryRanges as $rangeLabel => $count) {
            $batteryDistributionChart[] = [
                'range' => $rangeLabel,
                'count' => $count,
                'percentage' => $totalEvaluated > 0 ? round(($count / $totalEvaluated) * 100, 1) : 0,
            ];
        }

        // Promedio por marca (kWh)
        $batteryByBrand = [];
        foreach ($brandBatteries as $brandName => $bData) {
            $avgKwh = round($bData['total'] / $bData['count'], 1);
            $batteryByBrand[] = [
                'brand' => $brandName,
                'avg_battery_kwh' => $avgKwh,
                'battery_kwh' => $avgKwh,
                'vehicle_count' => $bData['count'],
            ];
        }
        usort($batteryByBrand, fn($a, $b) => $b['avg_battery_kwh'] <=> $a['avg_battery_kwh']);
        $batteryByBrand = array_slice($batteryByBrand, 0, 10);

        // Promedio por modelo (kWh)
        $batteryByModel = [];
        foreach ($modelBatteries as $modelName => $mData) {
            $avgKwh = round($mData['total'] / $mData['count'], 1);
            $batteryByModel[] = [
                'full_model' => $modelName,
                'avg_battery_kwh' => $avgKwh,
                'battery_kwh' => $avgKwh,
                'vehicle_count' => $mData['count'],
            ];
        }
        usort($batteryByModel, fn($a, $b) => $b['vehicle_count'] <=> $a['vehicle_count']);
        $batteryByModel = array_slice($batteryByModel, 0, 10);

        $batteryMetrics = [
            'avg_capacity_kwh' => count($validBatteries) > 0 ? round(array_sum($validBatteries) / count($validBatteries), 1) : 0,
            'min_capacity_kwh' => count($validBatteries) > 0 ? round(min($validBatteries), 1) : 0,
            'max_capacity_kwh' => count($validBatteries) > 0 ? round(max($validBatteries), 1) : 0,
            'vehicles_with_battery_count' => count($validBatteries),
            'unspecified_battery_count' => $unspecifiedBattery,
        ];

        // =========================================================================
        // 7. COMPORTAMIENTO Y ESTADÍSTICAS DE CARGA (DURACIÓN, HÁBITOS, RANKING)
        // =========================================================================
        $chargingQuery = ChargingSession::query()
            ->where('charging_sessions.status', 'Completed')
            ->whereNotNull('charging_sessions.start_time')
            ->whereNotNull('charging_sessions.stop_time')
            ->whereRaw('charging_sessions.stop_time > charging_sessions.start_time')
            ->whereRaw('TIMESTAMPDIFF(SECOND, charging_sessions.start_time, charging_sessions.stop_time) >= 60')
            ->whereRaw('TIMESTAMPDIFF(SECOND, charging_sessions.start_time, charging_sessions.stop_time) <= 86400');

        $this->applyFilters($chargingQuery, $request);

        $eagerRelations = ['user.vehicles', 'rfidTag.user.vehicles'];
        if (method_exists(ChargingSession::class, 'vehicle')) {
            $eagerRelations[] = 'vehicle';
        }

        $sessions = $chargingQuery
            ->with($eagerRelations)
            ->select([
                'charging_sessions.id',
                'charging_sessions.start_time',
                'charging_sessions.stop_time',
                'charging_sessions.total_energy_kwh',
                'charging_sessions.user_id',
                'charging_sessions.rfid_tag_id',
                'charging_sessions.station_id',
                'charging_sessions.vehicle_id',
                'charging_sessions.vehicle_brand',
                'charging_sessions.vehicle_model',
                'charging_sessions.vehicle_plate',
            ])
            ->get();

        // Si existen vehicle_id en las sesiones, pre-cargamos los vehículos para mapeo rápido
        $vehicleIds = $sessions->pluck('vehicle_id')->filter()->unique()->values()->toArray();
        $vehiclesById = !empty($vehicleIds) ? Vehicle::whereIn('id', $vehicleIds)->get()->keyBy('id') : collect();

        $resolveVehicle = function ($s) use ($vehiclesById) {
            $plate = trim((string) ($s->vehicle_plate ?? ''));
            $brand = trim((string) ($s->vehicle_brand ?? ''));
            $model = trim((string) ($s->vehicle_model ?? ''));

            // 1. Si no tiene placa/marca directa, buscar por vehicle_id pre-cargado
            if (($plate === '' || $brand === '') && !empty($s->vehicle_id) && isset($vehiclesById[$s->vehicle_id])) {
                $veh = $vehiclesById[$s->vehicle_id];
                $plate = $plate !== '' ? $plate : trim((string) ($veh->plate ?? ''));
                $brand = $brand !== '' ? $brand : trim((string) ($veh->brand ?? ''));
                $model = $model !== '' ? $model : trim((string) ($veh->model ?? ''));
            }

            // 2. Si la relación vehicle está cargada en el modelo
            if (($plate === '' || $brand === '') && isset($s->vehicle) && $s->vehicle) {
                $plate = $plate !== '' ? $plate : trim((string) ($s->vehicle->plate ?? ''));
                $brand = $brand !== '' ? $brand : trim((string) ($s->vehicle->brand ?? ''));
                $model = $model !== '' ? $model : trim((string) ($s->vehicle->model ?? ''));
            }

            // 3. Fallback a vehículos registrados del usuario
            if (($plate === '' || $brand === '') && $s->user && $s->user->vehicles && $s->user->vehicles->isNotEmpty()) {
                $firstUserVeh = $s->user->vehicles->first();
                $plate = $plate !== '' ? $plate : trim((string) ($firstUserVeh->plate ?? ''));
                $brand = $brand !== '' ? $brand : trim((string) ($firstUserVeh->brand ?? ''));
                $model = $model !== '' ? $model : trim((string) ($firstUserVeh->model ?? ''));
            }

            // 4. Fallback a vehículos del usuario asociado al RFID tag
            if (($plate === '' || $brand === '') && $s->rfidTag && $s->rfidTag->user && $s->rfidTag->user->vehicles && $s->rfidTag->user->vehicles->isNotEmpty()) {
                $firstRfidVeh = $s->rfidTag->user->vehicles->first();
                $plate = $plate !== '' ? $plate : trim((string) ($firstRfidVeh->plate ?? ''));
                $brand = $brand !== '' ? $brand : trim((string) ($firstRfidVeh->brand ?? ''));
                $model = $model !== '' ? $model : trim((string) ($firstRfidVeh->model ?? ''));
            }

            $plate = $plate !== '' ? strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $plate)) : 'S/P';
            $brand = $brand !== '' ? trim($brand) : 'Desconocida';
            $model = $model !== '' ? trim($model) : '';

            $fullModel = trim($brand . ' ' . $model);
            if (empty($fullModel) || $fullModel === 'Desconocida') {
                $fullModel = $brand !== 'Desconocida' ? $brand : 'Modelo No Especificado';
            }

            return [
                'plate' => $plate,
                'brand' => $brand,
                'model' => $model,
                'full_model' => $fullModel,
            ];
        };

        $totalSessionCount = $sessions->count();
        $totalMinutesCharged = 0.0;
        $totalEnergyChargedKwh = 0.0;

        $durationRanges = [
            '< 30 min' => 0,
            '30 - 60 min' => 0,
            '1 - 2 horas' => 0,
            '> 2 horas' => 0,
        ];

        $brandDurations = [];
        $modelDurations = [];

        $daysMap = [
            1 => ['day' => 'Lunes', 'sessions' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            2 => ['day' => 'Martes', 'sessions' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            3 => ['day' => 'Miércoles', 'sessions' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            4 => ['day' => 'Jueves', 'sessions' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            5 => ['day' => 'Viernes', 'sessions' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            6 => ['day' => 'Sábado', 'sessions' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            7 => ['day' => 'Domingo', 'sessions' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
        ];

        $hourlyPeriods = [
            'early_morning' => ['period' => 'Madrugada (00:00 - 06:00)', 'count' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            'morning'       => ['period' => 'Mañana (06:00 - 12:00)', 'count' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            'afternoon'     => ['period' => 'Tarde (12:00 - 18:00)', 'count' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
            'evening'       => ['period' => 'Noche (18:00 - 24:00)', 'count' => 0, 'kwh' => 0.0, 'minutes' => 0.0],
        ];

        $hours24 = array_fill(0, 24, ['sessions' => 0, 'kwh' => 0.0]);
        $vehicleStats = [];

        foreach ($sessions as $s) {
            $start = Carbon::parse($s->start_time);
            $stop = Carbon::parse($s->stop_time);
            $durationMinutes = max(0, $start->diffInSeconds($stop) / 60);
            $energyKwh = (float) ($s->total_energy_kwh ?? 0);

            $totalMinutesCharged += $durationMinutes;
            $totalEnergyChargedKwh += $energyKwh;

            // Rangos de duración
            if ($durationMinutes < 30) {
                $durationRanges['< 30 min']++;
            } elseif ($durationMinutes < 60) {
                $durationRanges['30 - 60 min']++;
            } elseif ($durationMinutes <= 120) {
                $durationRanges['1 - 2 horas']++;
            } else {
                $durationRanges['> 2 horas']++;
            }

            // Datos del vehículo
            $vehInfo = $resolveVehicle($s);
            $brand = $vehInfo['brand'];
            $fullModel = $vehInfo['full_model'];
            $plate = $vehInfo['plate'];

            // Duración por marca
            if (!isset($brandDurations[$brand])) {
                $brandDurations[$brand] = ['total_minutes' => 0.0, 'sessions' => 0, 'total_kwh' => 0.0];
            }
            $brandDurations[$brand]['total_minutes'] += $durationMinutes;
            $brandDurations[$brand]['sessions']++;
            $brandDurations[$brand]['total_kwh'] += $energyKwh;

            // Duración por modelo
            if (!isset($modelDurations[$fullModel])) {
                $modelDurations[$fullModel] = [
                    'total_minutes' => 0.0,
                    'sessions' => 0,
                    'total_kwh' => 0.0,
                    'brand' => $brand,
                    'model' => $vehInfo['model'],
                ];
            }
            $modelDurations[$fullModel]['total_minutes'] += $durationMinutes;
            $modelDurations[$fullModel]['sessions']++;
            $modelDurations[$fullModel]['total_kwh'] += $energyKwh;

            // Tiempo local (America/La_Paz UTC-4) para análisis temporal
            $localStart = Carbon::parse($s->start_time)->setTimezone('America/La_Paz');
            $isoDay = (int) $localStart->dayOfWeekIso; // 1 (Lunes) a 7 (Domingo)
            $hour = (int) $localStart->hour; // 0 a 23

            if (isset($daysMap[$isoDay])) {
                $daysMap[$isoDay]['sessions']++;
                $daysMap[$isoDay]['kwh'] += $energyKwh;
                $daysMap[$isoDay]['minutes'] += $durationMinutes;
            }

            if ($hour < 6) {
                $hourlyPeriods['early_morning']['count']++;
                $hourlyPeriods['early_morning']['kwh'] += $energyKwh;
                $hourlyPeriods['early_morning']['minutes'] += $durationMinutes;
            } elseif ($hour < 12) {
                $hourlyPeriods['morning']['count']++;
                $hourlyPeriods['morning']['kwh'] += $energyKwh;
                $hourlyPeriods['morning']['minutes'] += $durationMinutes;
            } elseif ($hour < 18) {
                $hourlyPeriods['afternoon']['count']++;
                $hourlyPeriods['afternoon']['kwh'] += $energyKwh;
                $hourlyPeriods['afternoon']['minutes'] += $durationMinutes;
            } else {
                $hourlyPeriods['evening']['count']++;
                $hourlyPeriods['evening']['kwh'] += $energyKwh;
                $hourlyPeriods['evening']['minutes'] += $durationMinutes;
            }

            if (isset($hours24[$hour])) {
                $hours24[$hour]['sessions']++;
                $hours24[$hour]['kwh'] += $energyKwh;
            }

            // Agrupación por vehículo para frecuencia y ranking
            $uniqueKey = $plate !== 'S/P' ? $plate : ('NO_PLATE_' . ($s->user_id ?? 'ANON_' . $s->id));
            if (!isset($vehicleStats[$uniqueKey])) {
                $vehicleStats[$uniqueKey] = [
                    'plate' => $plate,
                    'brand' => $brand,
                    'model' => $vehInfo['model'],
                    'full_model' => $fullModel,
                    'total_sessions' => 0,
                    'total_minutes' => 0.0,
                    'total_kwh' => 0.0,
                    'last_charge_at' => null,
                ];
            }
            $vehicleStats[$uniqueKey]['total_sessions']++;
            $vehicleStats[$uniqueKey]['total_minutes'] += $durationMinutes;
            $vehicleStats[$uniqueKey]['total_kwh'] += $energyKwh;

            $chargeTime = $s->start_time;
            if (!$vehicleStats[$uniqueKey]['last_charge_at'] || $chargeTime > $vehicleStats[$uniqueKey]['last_charge_at']) {
                $vehicleStats[$uniqueKey]['last_charge_at'] = $chargeTime;
            }
        }

        // Totales y promedios de duración
        $avgDurationMinutes = $totalSessionCount > 0 ? round($totalMinutesCharged / $totalSessionCount, 1) : 0;
        $totalHoursCharged = round($totalMinutesCharged / 60, 1);
        $avgPowerKw = $totalHoursCharged > 0 ? round($totalEnergyChargedKwh / $totalHoursCharged, 1) : 0;
        $avgEnergyPerSessionKwh = $totalSessionCount > 0 ? round($totalEnergyChargedKwh / $totalSessionCount, 1) : 0;

        // Distribución por rangos de duración
        $durationDistribution = [];
        foreach ($durationRanges as $rangeLabel => $count) {
            $durationDistribution[] = [
                'range' => $rangeLabel,
                'count' => $count,
                'percentage' => $totalSessionCount > 0 ? round(($count / $totalSessionCount) * 100, 1) : 0,
            ];
        }

        // Duración promedio por marca (Top 10)
        $durationByBrand = [];
        foreach ($brandDurations as $bName => $bData) {
            $durationByBrand[] = [
                'brand' => $bName,
                'avg_duration_minutes' => round($bData['total_minutes'] / $bData['sessions'], 1),
                'total_hours' => round($bData['total_minutes'] / 60, 1),
                'sessions_count' => $bData['sessions'],
                'total_kwh' => round($bData['total_kwh'], 1),
                'avg_kwh' => round($bData['total_kwh'] / $bData['sessions'], 1),
            ];
        }
        usort($durationByBrand, fn($a, $b) => $b['sessions_count'] <=> $a['sessions_count']);
        $durationByBrand = array_slice($durationByBrand, 0, 10);

        // Duración promedio por modelo (Top 10)
        $durationByModel = [];
        foreach ($modelDurations as $mName => $mData) {
            $durationByModel[] = [
                'full_model' => $mName,
                'brand' => $mData['brand'],
                'model' => $mData['model'],
                'avg_duration_minutes' => round($mData['total_minutes'] / $mData['sessions'], 1),
                'total_hours' => round($mData['total_minutes'] / 60, 1),
                'sessions_count' => $mData['sessions'],
                'total_kwh' => round($mData['total_kwh'], 1),
                'avg_kwh' => round($mData['total_kwh'] / $mData['sessions'], 1),
            ];
        }
        usort($durationByModel, fn($a, $b) => $b['sessions_count'] <=> $a['sessions_count']);
        $durationByModel = array_slice($durationByModel, 0, 10);

        // Distribución por días de la semana
        $daysDistribution = [];
        foreach ($daysMap as $dayNum => $d) {
            $daysDistribution[] = [
                'day' => $d['day'],
                'day_number' => $dayNum,
                'sessions_count' => $d['sessions'],
                'energy_kwh' => round($d['kwh'], 1),
                'avg_duration_minutes' => $d['sessions'] > 0 ? round($d['minutes'] / $d['sessions'], 1) : 0,
                'percentage' => $totalSessionCount > 0 ? round(($d['sessions'] / $totalSessionCount) * 100, 1) : 0,
            ];
        }

        // Distribución por franjas horarias
        $hourlyDistribution = [];
        foreach ($hourlyPeriods as $key => $h) {
            $hourlyDistribution[] = [
                'key' => $key,
                'period' => $h['period'],
                'count' => $h['count'],
                'energy_kwh' => round($h['kwh'], 1),
                'avg_duration_minutes' => $h['count'] > 0 ? round($h['minutes'] / $h['count'], 1) : 0,
                'percentage' => $totalSessionCount > 0 ? round(($h['count'] / $totalSessionCount) * 100, 1) : 0,
            ];
        }

        // Distribución horaria 24 horas y detección de hora pico
        $hourly24h = [];
        $peakHour = null;
        $peakHourCount = -1;
        for ($hr = 0; $hr < 24; $hr++) {
            $sess = $hours24[$hr]['sessions'];
            $kwh = round($hours24[$hr]['kwh'], 1);
            if ($sess > $peakHourCount && $sess > 0) {
                $peakHourCount = $sess;
                $peakHour = $hr;
            }
            $hourly24h[] = [
                'hour' => $hr,
                'formatted_hour' => str_pad($hr, 2, '0', STR_PAD_LEFT) . ':00',
                'sessions_count' => $sess,
                'energy_kwh' => $kwh,
                'percentage' => $totalSessionCount > 0 ? round(($sess / $totalSessionCount) * 100, 1) : 0,
            ];
        }

        // Ranking de vehículos más activos y segmentación
        $activeVehiclesCount = count($vehicleStats);
        $avgSessionsPerActiveVehicle = $activeVehiclesCount > 0 ? round($totalSessionCount / $activeVehiclesCount, 1) : 0;

        $frequentCount = 0;
        $occasionalCount = 0;
        $topActiveVehiclesList = [];

        foreach ($vehicleStats as $vKey => $vData) {
            $totSessions = $vData['total_sessions'];
            if ($totSessions >= 5) {
                $frequentCount++;
            } else {
                $occasionalCount++;
            }

            $totHours = round($vData['total_minutes'] / 60, 1);
            $totKwh = round($vData['total_kwh'], 1);
            $avgPower = $totHours > 0 ? round($totKwh / $totHours, 1) : 0;

            $lastChargeFormatted = $vData['last_charge_at']
                ? Carbon::parse($vData['last_charge_at'])->setTimezone('America/La_Paz')->format('Y-m-d H:i:s')
                : null;

            $topActiveVehiclesList[] = [
                'plate' => $vData['plate'],
                'brand' => $vData['brand'],
                'model' => $vData['model'] !== '' ? $vData['model'] : $vData['full_model'],
                'full_model' => $vData['full_model'],
                'total_sessions' => $totSessions,
                'total_hours' => $totHours,
                'avg_duration_minutes' => round($vData['total_minutes'] / $totSessions, 1),
                'total_kwh' => $totKwh,
                'avg_power_kw' => $avgPower,
                'last_charge_at' => $lastChargeFormatted,
            ];
        }

        usort($topActiveVehiclesList, function ($a, $b) {
            if ($b['total_sessions'] === $a['total_sessions']) {
                return $b['total_kwh'] <=> $a['total_kwh'];
            }
            return $b['total_sessions'] <=> $a['total_sessions'];
        });

        $topActiveVehicles = array_slice($topActiveVehiclesList, 0, 15);

        $inactiveCount = max(0, $totalVehicles - $activeVehiclesCount);
        $totalRegisteredOrEvaluated = max($totalVehicles, $activeVehiclesCount);

        $usageSegmentation = [
            'frequent_count' => $frequentCount,
            'frequent_percentage' => $totalRegisteredOrEvaluated > 0 ? round(($frequentCount / $totalRegisteredOrEvaluated) * 100, 1) : 0,
            'occasional_count' => $occasionalCount,
            'occasional_percentage' => $totalRegisteredOrEvaluated > 0 ? round(($occasionalCount / $totalRegisteredOrEvaluated) * 100, 1) : 0,
            'inactive_count' => $inactiveCount,
            'inactive_percentage' => $totalRegisteredOrEvaluated > 0 ? round(($inactiveCount / $totalRegisteredOrEvaluated) * 100, 1) : 0,
            'total_registered' => $totalVehicles,
            'total_active' => $activeVehiclesCount,
        ];

        $chargingStatistics = [
            'duration_summary' => [
                'avg_duration_minutes' => $avgDurationMinutes,
                'total_hours_charged' => $totalHoursCharged,
                'avg_power_kw' => $avgPowerKw,
                'avg_energy_per_session_kwh' => $avgEnergyPerSessionKwh,
                'total_sessions_evaluated' => $totalSessionCount,
                'total_energy_kwh' => round($totalEnergyChargedKwh, 1),
                'distribution_by_duration' => $durationDistribution,
            ],
            'duration_by_brand' => $durationByBrand,
            'duration_by_model' => $durationByModel,
            'days_distribution' => $daysDistribution,
            'hourly_distribution' => $hourlyDistribution,
            'hourly_24h' => $hourly24h,
            'peak_hour' => $peakHour !== null ? [
                'hour' => $peakHour,
                'formatted_hour' => str_pad($peakHour, 2, '0', STR_PAD_LEFT) . ':00',
                'sessions_count' => $peakHourCount,
            ] : null,
            'frequency_and_ranking' => [
                'active_vehicles_count' => $activeVehiclesCount,
                'avg_sessions_per_active_vehicle' => $avgSessionsPerActiveVehicle,
                'top_active_vehicles' => $topActiveVehicles,
                'usage_segmentation' => $usageSegmentation,
            ],
            'energy_efficiency' => [
                'total_energy_kwh' => round($totalEnergyChargedKwh, 1),
                'total_hours_charged' => $totalHoursCharged,
                'avg_power_kw' => $avgPowerKw,
                'avg_energy_per_session_kwh' => $avgEnergyPerSessionKwh,
            ],
        ];

            return [
                'success' => true,
                'metrics' => [
                    'total_registered_vehicles' => $totalVehicles,
                    'total_registered_clients' => $totalClients,
                    'avg_vehicles_per_client' => $avgVehiclesPerClient,
                    'clients_with_vehicle_no_charges_count' => count($vehicleNoChargeUserIds),
                    'clients_with_charges_no_vehicle_count' => count(array_filter($chargeNoVehicleUserIds)),
                    'avg_battery_capacity_kwh' => $batteryMetrics['avg_capacity_kwh'],
                    // Nuevas métricas añadidas para KPIs rápidos
                    'active_charging_vehicles' => $activeVehiclesCount,
                    'total_charging_sessions' => $totalSessionCount,
                    'avg_charging_duration_minutes' => $avgDurationMinutes,
                    'total_charging_hours' => $totalHoursCharged,
                    'avg_charging_power_kw' => $avgPowerKw,
                    'avg_sessions_per_active_vehicle' => $avgSessionsPerActiveVehicle,
                ],
                'top_brands' => $topBrands,
                'top_models' => $topModels,
                'plates_distribution' => $platesDistribution,
                'top_clients_by_plates' => $topClientsByPlates,
                'battery_analysis' => [
                    'metrics' => $batteryMetrics,
                    'distribution' => $batteryDistributionChart,
                    'ranges_summary' => $batteryRanges,
                    'by_brand' => $batteryByBrand,
                    'by_model' => $batteryByModel,
                ],
                'gap_analysis' => [
                    'clients_with_vehicle_no_charges' => $clientsWithVehicleNoCharges,
                    'clients_with_charges_no_vehicle' => $clientsWithChargeNoVehicle,
                ],
                'charging_statistics' => $chargingStatistics,
                'charging_behavior' => $chargingStatistics,
            ];
        });

        return response()->json($response);
    }
}

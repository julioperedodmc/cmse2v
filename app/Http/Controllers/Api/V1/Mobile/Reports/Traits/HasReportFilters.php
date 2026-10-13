<?php

namespace App\Http\Controllers\Api\V1\Mobile\Reports\Traits;

use App\Models\Company;
use App\Models\Connector;
use App\Models\Station;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait HasReportFilters
{
    /**
     * Endpoint: Opciones de filtrado dinámico
     */
    public function getFilterOptions()
    {
        $data = \Illuminate\Support\Facades\Cache::remember('report_filter_options', now()->addMinutes(30), function () {
            $stations = Station::select('id', 'name')->get();
            $connectorTypes = Connector::distinct()->pluck('type')->filter()->values()->toArray();
            $vehicleBrands = Vehicle::distinct()->pluck('brand')->filter()->values()->toArray();
            $companies = Company::select('id', 'name')->get();

            return [
                'success' => true,
                'stations' => $stations,
                'connector_types' => $connectorTypes,
                'vehicle_brands' => $vehicleBrands,
                'companies' => $companies,
            ];
        });

        return response()->json($data);
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

        $localTimeExpr = 'DATE_SUB(charging_sessions.start_time, INTERVAL 4 HOUR)';

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
            $query->where(function ($w) use ($vehicleBrand) {
                $w->where('charging_sessions.vehicle_brand', 'LIKE', '%' . $vehicleBrand . '%')
                    ->orWhereHas('user.vehicles', function ($q) use ($vehicleBrand) {
                        $q->where('brand', 'LIKE', '%' . $vehicleBrand . '%');
                    });
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
}

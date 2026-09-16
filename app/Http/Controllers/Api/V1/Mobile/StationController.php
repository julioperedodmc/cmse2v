<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Location;
use App\Models\Connector;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function lookup(Request $request)
    {
        $cbId = trim($request->charge_box_id);
        $station = Station::where('charge_box_id', $cbId)->first();
        return response()->json($station);
    }

    public function index()
    {
        try {
            // Live-sync state from Steve server on index requests
            try {
                \Illuminate\Support\Facades\Artisan::call('steve:sync-status');
            } catch (\Throwable $te) {
                \Log::warning("Live sync status failed: " . $te->getMessage());
            }

            $stations = Station::with(['location', 'connectors' => function($query) {
                $query->where('connector_id', '>', 0);
            }])
                ->where('is_active', true)
                ->get();

            return response()->json($stations);
        } catch (\Exception $e) {
            \Log::error("StationController Index Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $station = Station::with(['location', 'connectors'])->findOrFail($id);
            return response()->json($station);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }
}

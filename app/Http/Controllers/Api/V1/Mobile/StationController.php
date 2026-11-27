<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Location;
use App\Models\Connector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StationController extends Controller
{
    public function lookup(Request $request)
    {
        $cbId = trim($request->charge_box_id);
        $station = Station::where('charge_box_id', $cbId)->first();
        return response()->json($station);
    }

    private const LIVE_SYNC_LOCK_KEY = 'steve:sync-status:map';
    private const LIVE_SYNC_INTERVAL_SECONDS = 30;

    /**
     * Refreshes connector status from SteVe after the response is sent, at most once every
     * 30 seconds across all users. The sync pushes each connector to Firebase one by one
     * (~0.45 s each), so running it inline made every map load wait several seconds.
     */
    private function scheduleLiveStatusSync(): void
    {
        if (!Cache::add(self::LIVE_SYNC_LOCK_KEY, true, self::LIVE_SYNC_INTERVAL_SECONDS)) {
            return;
        }

        app()->terminating(function () {
            try {
                Artisan::call('steve:sync-status');
            } catch (\Throwable $e) {
                Log::warning('Live sync status failed: ' . $e->getMessage());
            }
        });
    }

    public function index()
    {
        try {
            $this->scheduleLiveStatusSync();

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

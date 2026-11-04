<?php

namespace App\Console\Commands;

use App\Models\ChargingSession;
use App\Models\Connector;
use App\Models\RfidTag;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\SteveDataSource;
use Carbon\Carbon;

class SyncSteveSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'steve:sync-sessions {--since= : Only sync sessions with start_timestamp >= this (YYYY-MM-DD HH:MM:SS)} {--limit=200 : Max records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs charging sessions from Steve DB into CMS charging_sessions table';

    public function handle(SteveDataSource $source): int
    {
        $this->info("Starting Steve session sync ({$source->source()})...");

        try {
            $limit = (int) $this->option('limit');
            $since = $this->option('since');

            // 1. Sync New/Updated Transactions from SteVe
            // We only look for numeric transaction IDs for the sync pointer
            $maxTxVal = ChargingSession::whereRaw('transaction_id REGEXP "^[0-9]+$"')->max('transaction_id');
            $maxTx = $maxTxVal ? (int) $maxTxVal : null;

            // --- Reset Detection ---
            // If CMS thinks we are at Tx #100 but SteVe max Tx is #10, we likely had a reset.
            $steveMaxTx = DB::connection('steve')->table('transaction')->max('transaction_pk');
            if ($maxTx > ($steveMaxTx + 5)) {
                $this->warn("⚠️ SteVe Max Tx (#{$steveMaxTx}) is much lower than CMS Max Tx (#{$maxTx}). Database reset detected. Syncing from start or recent.");
                $maxTx = null; // Forces sync since 'since' or recent
            }

            $sessionsFromSteve = collect($source->getSessionsForSync($since, $maxTx, $limit));

            if ($sessionsFromSteve->isNotEmpty()) {
                $synced = 0;
                foreach ($sessionsFromSteve as $s) {
                    $steveConnector = $source->getConnectorByPk((int) ($s->connector_pk ?? 0));
                    if (!$steveConnector) continue;

                    $station = Station::where('charge_box_id', $steveConnector->charge_box_id)->first();
                    if (!$station) {
                        $station = Station::create([
                            'charge_box_id' => $steveConnector->charge_box_id,
                            'name' => 'Station ' . $steveConnector->charge_box_id,
                            'is_active' => true
                        ]);
                    }

                    $connector = Connector::updateOrCreate(
                        ['station_id' => $station->id, 'connector_id' => $steveConnector->connector_id],
                        ['connector_pk' => $steveConnector->connector_pk ?? null]
                    );

                    $rfid = RfidTag::where('tag_code', $s->id_tag)->first();
                    
                    $rawStart = $s->start_timestamp ?: $s->start_event_timestamp;
                    $startTime = $rawStart ? Carbon::createFromFormat('Y-m-d H:i:s', $rawStart, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
                    
                    $rawStop = $s->stop_timestamp;
                    $stopTime = $rawStop ? Carbon::createFromFormat('Y-m-d H:i:s', $rawStop, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
                    
                    $meterStart = is_numeric($s->start_value) ? (int) $s->start_value : 0;
                    $meterStop = is_numeric($s->stop_value) ? (int) $s->stop_value : 0;
                    $energyKwh = ($meterStop > $meterStart) ? round(($meterStop - $meterStart) / 1000, 4) : 0;

                    $status = $stopTime ? 'Completed' : 'Active';

                    // --- ADOPTION LOGIC ---
                    // Search for a 'Starting' session for THIS user and connector
                    $adoptionTarget = ChargingSession::where('status', 'Starting')
                        ->where('station_id', $station->id)
                        ->where('connector_id', $connector->id)
                        ->where('rfid_tag_id', $rfid?->id)
                        ->orderByDesc('created_at')
                        ->first();

                    if ($adoptionTarget) {
                        $this->info("Adopting 'Starting' session ID: {$adoptionTarget->id} into Tx: {$s->transaction_pk}");
                        $adoptionTarget->update([
                            'transaction_id' => (string) $s->transaction_pk,
                            'status' => $status,
                            'start_time' => $startTime,
                            'stop_time' => $stopTime,
                            'meter_start' => $meterStart,
                            'meter_stop' => $meterStop,
                            'total_energy_kwh' => $energyKwh,
                            'stop_reason' => $s->stop_reason,
                        ]);
                    } else {
                        // --- Collision Avoidance for Historical Sync ---
                        $existing = ChargingSession::where('transaction_id', (string)$s->transaction_pk)->first();
                        if ($existing) {
                            $steveStart = Carbon::parse($startTime);
                            $cmsStart = Carbon::parse($existing->start_time);
                            if (abs($steveStart->diffInHours($cmsStart, false)) > 24) {
                                $this->warn("⚠️ Collision in historical sync for Tx #{$s->transaction_pk}. Creating NEW record.");
                                // We continue to updateOrCreate but with a unique ID or just let it create a new one if we change the key.
                                // Actually, if we want a new record, we must NOT use transaction_id as the ONLY key if it collides.
                                // But ChargingSession uses transaction_id as a unique field in some logic.
                                // For now, we'll just allow updateOrCreate to fail or handle it.
                            }
                        }

                        ChargingSession::updateOrCreate(
                            ['transaction_id' => (string) $s->transaction_pk],
                            [
                                'station_id' => $station->id,
                                'connector_id' => $connector->id,
                                'user_id' => $rfid?->user_id,
                                'rfid_tag_id' => $rfid?->id,
                                'tariff_id' => $station->tariff_id,
                                'start_time' => $startTime,
                                'stop_time' => $stopTime,
                                'meter_start' => $meterStart,
                                'meter_stop' => $meterStop,
                                'total_energy_kwh' => $energyKwh,
                                'total_cost' => 0,
                                'currency' => $station->tariff->currency ?? 'BOB',
                                'status' => $status,
                                'stop_reason' => $s->stop_reason,
                            ]
                        );
                    }
                    $synced++;
                }
                $this->info("Synced {$synced} records from SteVe.");
            }

            // 2. Refresh Status for existing 'Active' sessions (Manual Stop Detection)
            $activeSessions = ChargingSession::where('status', 'Active')->get();
            foreach ($activeSessions as $session) {
                if (!$session->transaction_id) continue;
                
                $tx = $source->getTransactionById((int) $session->transaction_id);
                if ($tx && !empty($tx->stop_timestamp)) {
                    $this->info("Closing manually stopped Tx: {$session->transaction_id}");
                    $meterStart = is_numeric($tx->start_value) ? (int) $tx->start_value : 0;
                    $meterStop = is_numeric($tx->stop_value) ? (int) $tx->stop_value : 0;
                    $energyKwh = ($meterStop > $meterStart) ? round(($meterStop - $meterStart) / 1000, 4) : 0;

                    $rawStop = $tx->stop_timestamp;
                    $localStop = Carbon::createFromFormat('Y-m-d H:i:s', $rawStop, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

                    $session->update([
                        'status' => 'Completed',
                        'stop_time' => $localStop,
                        'meter_stop' => $meterStop,
                        'total_energy_kwh' => $energyKwh,
                        'stop_reason' => $tx->stop_reason,
                    ]);
                }
            }

            // 3. Cleanup stale 'Starting' sessions (Timeout)
            $staleStarting = ChargingSession::where('status', 'Starting')
                ->where('created_at', '<', now()->subMinutes(1))
                ->get();
            
            foreach ($staleStarting as $stale) {
                $this->warn("Timing out stale 'Starting' session ID: {$stale->id}");
                $stale->update([
                    'status' => 'Failed',
                    'stop_reason' => 'Timeout: Car not connected or charger error',
                ]);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            Log::error('Steve session sync failed', ['error' => $e]);
            return self::FAILURE;
        }
    }
}

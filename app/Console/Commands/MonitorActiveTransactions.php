<?php

namespace App\Console\Commands;

use App\Models\RfidTag;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\ChargingSession;
use App\Models\ChargingSessionNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\Cookie\CookieJar;
use App\Services\SteveDataSource;
use App\Services\BillingService;
use App\Services\SteveService;

class MonitorActiveTransactions extends Command
{
    protected $signature = 'steve:monitor-transactions {--daemon}';
    protected $description = 'Monitors active transactions in Steve, calculates cost, and enforces credit limits';

    public function handle(SteveDataSource $source, BillingService $billing, SteveService $steve)
    {
        $isDaemon = $this->option('daemon');

        do {
            $source->clearCache();
            $this->info("🔍 Scanning ALL transactions (Active & Recently Completed) in Steve ({$source->source()})...");

            try {
                $recentTxs = collect($source->getTransactionsForMonitoring(20));

                foreach ($recentTxs as $tx) {
                    $this->processTransaction($tx, $source, $billing, $steve);
                }

                $this->cleanupStuckSessions();

            } catch (\Exception $e) {
                $this->error("🔥 Monitor Failed: " . $e->getMessage());
                Log::error("Monitor Failed", ['error' => $e]);
            }

            if ($isDaemon) {
                sleep(5);
            }
        } while ($isDaemon);
    }

    private function processTransaction($tx, SteveDataSource $source, BillingService $billing, SteveService $steve)
    {
        $txId = $tx->transaction_pk ?? $tx->id;

        // Command-level transaction lock to prevent concurrent double processing using atomic Redis store
        $lock = \Illuminate\Support\Facades\Cache::store('redis')->lock('process_tx_' . $txId, 30);

        if (!$lock->get()) {
            $this->warn("   ⏳ Tx #{$txId} is locked by another process. Skipping.");
            return;
        }

        try {
            $tagCode = $tx->id_tag ?? null;
            $startTimestamp = $tx->start_timestamp ?? null;
            $stopTimestamp = $tx->stop_timestamp ?? null;
            $stopValue = $tx->stop_value ?? null;
            $stopReason = $tx->stop_reason ?? null;

            $existingSession = ChargingSession::where('transaction_id', (string)$txId)
                ->where('status', '!=', 'Starting')
                ->first();

            // --- Collision Detection ---
            if ($existingSession) {
                $steveStart = \Carbon\Carbon::parse($startTimestamp);
                $cmsStart = \Carbon\Carbon::parse($existingSession->start_time);
                
                // If the transaction ID is the same but the start date is more than 24 hours apart, 
                // it's a collision from a legacy SteVe database or a reset.
                if (abs($steveStart->diffInHours($cmsStart, false)) > 24) {
                    $this->warn("⚠️ Collision detected for Tx #{$txId}. CMS Session ID {$existingSession->id} is from {$cmsStart}, but SteVe reports {$steveStart}. Renaming old record to avoid collision.");
                    
                    // Rename the old transaction ID to free it up for the new one
                    $existingSession->update(['transaction_id' => "{$txId}-OLD-COLLISION-" . $existingSession->id]);
                    
                    $existingSession = null;
                }
            }

            if ($existingSession && $existingSession->status === 'Completed') {
                $this->line("↪️  Tx #{$txId} already completed in CMS.");
                return;
            }

            $this->line("👉 Analyzing Tx #{$txId} (Tag: {$tagCode})");

            // 1. Identify User & Wallet (Case-Insensitive match)
            $tag = RfidTag::whereRaw('LOWER(tag_code) = ?', [strtolower(trim($tagCode))])->first();
            $userId = $tag?->user_id;

            // 2. Determine Consumption (kWh)
            $isCompleted = !is_null($stopTimestamp);
            if ($isCompleted) {
                $currentWh = floatval($stopValue);
            } else {
                $lastEnergyMeter = $source->getLatestEnergyMeterValue((int) $txId);
                $currentWh = $lastEnergyMeter ? floatval($lastEnergyMeter->value) : 0;
            }

            $startWh = floatval($tx->start_value ?? 0);
            $consumedKwh = max(0, ($currentWh - $startWh) / 1000);

            // 3. Resolve Station and Connector
            $connector = $source->getConnectorByPk((int) ($tx->connector_pk ?? 0));
            $chargeBoxId = $connector->charge_box_id ?? 'Unknown';
            $station = Station::where('charge_box_id', $chargeBoxId)->first();
            
            // CMS-side connector lookup
            $cmsConnector = \App\Models\Connector::where('connector_pk', $tx->connector_pk)->first();
            
            // 4. Resolve Applicable Tariff
            $tariff = \App\Models\Tariff::resolveForStation($station, $startTimestamp);

            // 5. Update/Create ChargingSession in CMS
            if (!$existingSession) {
                // Adoption logic for 'Starting' sessions
                // Search by Charge Box ID and Tag/User
                $existingSession = ChargingSession::where('status', 'Starting')
                    ->whereHas('station', function($q) use ($chargeBoxId) {
                        $q->where('charge_box_id', $chargeBoxId);
                    })
                    ->where(function($q) use ($tag, $userId) {
                        $q->where('rfid_tag_id', $tag?->id)
                          ->when($userId, fn($qq) => $qq->orWhere('user_id', $userId));
                    })
                    ->orderByDesc('created_at')
                    ->first();
                    
                if ($existingSession) {
                    $this->info("🤝 Adopting existing 'Starting' session #{$existingSession->id} for Tx #{$txId}");
                }
            }

            $session = ChargingSession::updateOrCreate(
                ['id' => $existingSession?->id ?? 0],
                [
                    'transaction_id' => (string)$txId,
                    'station_id' => $station->id ?? 1,
                    'connector_id' => $cmsConnector?->id ?? 1,
                    'user_id' => $userId,
                    'rfid_tag_id' => $tag?->id,
                    'tariff_id' => $tariff?->id,
                    'total_energy_kwh' => max($existingSession?->total_energy_kwh ?? 0, $consumedKwh),
                    'start_time' => $startTimestamp,
                    'stop_time' => $stopTimestamp,
                    'meter_start' => $startWh,
                    'meter_stop' => $currentWh,
                    'status' => $isCompleted ? 'Completed' : 'Active',
                ]
            );
            
            // --- SoC Tracking ---
            if (is_null($session->start_soc)) {
                $earliestSoc = $source->getEarliestSocMeterValue((int) $txId);
                if ($earliestSoc) {
                    $session->start_soc = (int) $earliestSoc->value;
                }
            }
            
            $latestSoc = $source->getLatestSocMeterValue((int) $txId);
            if ($latestSoc) {
                $session->current_soc = (int) $latestSoc->value;
                if ($isCompleted) {
                    $session->stop_soc = (int) $latestSoc->value;
                }
            } elseif ($isCompleted && !is_null($session->stop_soc)) {
                $session->current_soc = $session->stop_soc;
            }

            // SoC Notification Logic (80% and 95%)
            if ($session->user_id && !is_null($session->current_soc)) {
                $this->checkAndSendSocNotifications($session, $tariff);
            }

            $currentPower = 0;
            $latestPower = $source->getLatestPowerMeterValue((int) $txId);
            if ($latestPower) {
                $currentPower = round((float)$latestPower->value / 1000, 2); // Convert W to kW if necessary, or keep as is if already kW
                // Note: SteVe usually stores Power in Watts. We'll assume Watts and convert to kW.
            }

            // 5. Initial Fee Processing (If not debited yet based on debited_amount and session_fee)
            $initialFeeProcessed = ((float)$session->debited_amount > 0 || (float)$session->session_fee > 0);
            if ($session->wasRecentlyCreated || !$initialFeeProcessed) {
                $ok = $billing->processInitialFee($session);
                if (!$ok) {
                    $this->error("   🛑 INSUFFICIENT FUNDS FOR INITIAL FEE! Sending RemoteStop...");
                    $steve->remoteStop($chargeBoxId, (int)$txId);
                    $session->update(['status' => 'CreditStopped', 'stop_reason' => 'CreditLimitExceeded']);
                    $lock->release();
                    return;
                }
                $session->refresh(); // CRITICAL: Ensure debited_amount is updated in this object to avoid double charge in the next step
            }

            // 6. Calculate Cost and Billing
            $pricing = $billing->calculateSessionCost($session, $consumedKwh, $isCompleted ? \Carbon\Carbon::parse($stopTimestamp) : null);

            if (!$isCompleted) {
                $ok = $billing->processIncrementalDebit($session, $pricing);
                if (!$ok) {
                    $this->error("   🛑 INSUFFICIENT FUNDS! Sending RemoteStop...");
                    $steve->remoteStop($chargeBoxId, (int)$txId);
                    $session->update(['status' => 'CreditStopped', 'stop_reason' => 'CreditLimitExceeded']);
                }
                $session->refresh(); // CRITICAL: Get the updated total_cost and debited_amount from DB
            } else {
                $billing->finalizeBilling($session);
                $session->refresh();
            }

            // 7. Update other metrics that might have changed
            $session->total_energy_kwh = $consumedKwh;
            $session->meter_stop = $currentWh;
            $session->save();

            // Notify user when charging completes
            if ($isCompleted && $session->user_id) {
                $this->checkAndSendCompletionNotification($session, $station);
            }

            // --- Real-time Sync to Firebase ---
            try {
                $firebaseStatus = $isCompleted ? 'AVAILABLE' : 'CHARGING';
                \App\Services\FirebaseService::syncStationData($chargeBoxId, [
                    'status' => $firebaseStatus,
                    'connectors' => [
                        (string)$cmsConnector?->connector_id => [
                            'status' => $firebaseStatus,
                            'current_power_kw' => $currentPower,
                            'current_soc' => $session->current_soc,
                            'total_energy_kwh' => $consumedKwh,
                            'total_cost' => $pricing['total'],
                        ]
                    ]
                ]);
            } catch (\Throwable $e) {
                Log::error("Firebase sync failed in monitor: " . $e->getMessage());
            }

            $this->line("   ✅ Synced Tx #{$txId}: " . ($isCompleted ? "Completed" : "Active") . " | {$consumedKwh} kWh | \${$pricing['total']}");
        } finally {
            $lock->release();
        }
    }

    private function cleanupStuckSessions()
    {
        $stuckSessions = ChargingSession::where('status', 'Starting')
            ->where('created_at', '<', now()->subMinutes(2))
            ->get();

        foreach ($stuckSessions as $session) {
            $session->update([
                'status' => 'Failed',
                'stop_time' => now(),
                'stop_reason' => 'TimeoutWaitingForStart'
            ]);

            $user = $session->user;
            if ($user) {
                $stationName = $session->station?->name ?? 'Cargador';
                $connectorId = $session->connector_id ?? '1';
                $user->notify(new \App\Notifications\GeneralNotification(
                    'Carga fallida',
                    "La carga en el dispensador {$stationName} (Conector {$connectorId}) no se pudo iniciar por tiempo de espera agotado. Por favor verifica esto y vuelve a intentarlo.",
                    ['type' => 'CHARGING_FAILED', 'station_id' => $session->station_id]
                ));
            }
            
            $this->warn("🧹 Cleaned up stuck session #{$session->id} for User #{$session->user_id}");
        }
    }

    private function checkAndSendSocNotifications(ChargingSession $session, ?Tariff $tariff = null): void
    {
        $user = $session->user;
        if (!$user) {
            return;
        }

        $soc = (int) $session->current_soc;
        $target80 = (int) ($tariff->target_soc ?? 80);

        // 1. Notificación al 80% (o SOC objetivo de la tarifa)
        if ($soc >= $target80) {
            $alreadySent80 = false;
            try {
                $alreadySent80 = ChargingSessionNotification::where('charging_session_id', $session->id)
                    ->where('type', 'SOC_80')
                    ->exists();
            } catch (\Throwable $e) {
                $alreadySent80 = !is_null($session->soc_notification_sent_at);
            }

            if (!$alreadySent80) {
                try {
                    $msg80 = !empty($tariff?->soc_reached_message)
                        ? str_replace(['{soc}', '{minutes}'], [(string)$soc, '15'], $tariff->soc_reached_message)
                        : "Tu vehículo ha alcanzado el {$target80}% de carga. Por favor verifique el estado de su carga.";

                    $user->notify(new \App\Notifications\GeneralNotification(
                        "Carga al {$target80}%",
                        $msg80,
                        ['type' => 'SOC_80', 'session_id' => $session->id, 'soc' => $soc]
                    ));

                    try {
                        ChargingSessionNotification::create([
                            'charging_session_id' => $session->id,
                            'user_id' => $user->id,
                            'type' => 'SOC_80',
                            'sent_at' => now(),
                            'data' => ['soc' => $soc],
                        ]);
                    } catch (\Throwable $e) {
                        // Table may be pending migration, safe to continue
                    }

                    // Actualizar campo histórico
                    if (is_null($session->soc_notification_sent_at)) {
                        $session->update(['soc_notification_sent_at' => now()]);
                    }

                    $this->info("📧 SoC {$target80}% Notification sent to User #{$user->id} for Session #{$session->id} ({$soc}%)");
                } catch (\Throwable $e) {
                    Log::error("Failed to send 80% SoC notification", ['error' => $e->getMessage(), 'session_id' => $session->id]);
                }
            }
        }

        // 2. Notificación al 95%
        if ($soc >= 95) {
            $alreadySent95 = false;
            try {
                $alreadySent95 = ChargingSessionNotification::where('charging_session_id', $session->id)
                    ->where('type', 'SOC_95')
                    ->exists();
            } catch (\Throwable $e) {
                $alreadySent95 = false;
            }

            if (!$alreadySent95) {
                try {
                    $msg95 = "Tu vehículo ha alcanzado el 95% de carga. La sesión está próxima a completarse.";

                    $user->notify(new \App\Notifications\GeneralNotification(
                        'Carga al 95%',
                        $msg95,
                        ['type' => 'SOC_95', 'session_id' => $session->id, 'soc' => $soc]
                    ));

                    try {
                        ChargingSessionNotification::create([
                            'charging_session_id' => $session->id,
                            'user_id' => $user->id,
                            'type' => 'SOC_95',
                            'sent_at' => now(),
                            'data' => ['soc' => $soc],
                        ]);
                    } catch (\Throwable $e) {
                        // Safe ignore
                    }

                    $this->info("📧 SoC 95% Notification sent to User #{$user->id} for Session #{$session->id} ({$soc}%)");
                } catch (\Throwable $e) {
                    Log::error("Failed to send 95% SoC notification", ['error' => $e->getMessage(), 'session_id' => $session->id]);
                }
            }
        }
    }

    private function checkAndSendCompletionNotification(ChargingSession $session, ?Station $station = null): void
    {
        $user = $session->user;
        if (!$user) {
            return;
        }

        $alreadySentCompleted = ChargingSessionNotification::where('charging_session_id', $session->id)
            ->where('type', 'COMPLETED')
            ->exists();

        if (!$alreadySentCompleted) {
            try {
                $stationName = $station?->name ?? ($session->station?->name ?? 'Estación de Carga');
                $kwh = number_format((float) $session->total_energy_kwh, 2);
                $cost = number_format((float) $session->total_cost, 2);

                $user->notify(new \App\Notifications\GeneralNotification(
                    'Carga finalizada',
                    "Tu carga en {$stationName} ha finalizado. Consumo: {$kwh} kWh | Total: Bs {$cost}. Ya puedes desconectar tu vehículo.",
                    [
                        'type' => 'CHARGING_COMPLETED',
                        'session_id' => $session->id,
                        'kwh' => (float) $session->total_energy_kwh,
                        'total_cost' => (float) $session->total_cost,
                        'station_id' => $session->station_id,
                    ]
                ));

                ChargingSessionNotification::create([
                    'charging_session_id' => $session->id,
                    'user_id' => $user->id,
                    'type' => 'COMPLETED',
                    'sent_at' => now(),
                    'data' => [
                        'kwh' => (float) $session->total_energy_kwh,
                        'total_cost' => (float) $session->total_cost,
                        'currency' => $session->currency ?? 'BOB',
                    ],
                ]);

                $this->info("📧 Completion Notification sent to User #{$user->id} for Session #{$session->id}");
            } catch (\Throwable $e) {
                Log::error("Failed to send Completion notification", ['error' => $e->getMessage(), 'session_id' => $session->id]);
            }
        }
    }
}

<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Tariff;
use App\Models\Wallet;
use App\Models\Product;
use App\Models\WalletTransaction;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BillingService
{
    /**
     * Calculates the cost based on energy consumption and time blocks.
     * If a session spans multiple blocks, it interpolates energy usage based on time.
     */
    public function calculateSessionCost(ChargingSession $session, float $kwh, ?Carbon $stopTime = null): array
    {
        // DEBUG: var_dump($kwh);
        $tariff = $session->tariff ?: Tariff::first();
        $start = $session->start_time;
        $stop = $stopTime ?? Carbon::now();
        $durationSeconds = max(1, (int) $start->diffInSeconds($stop));

        $blocks = $this->getTariffBlocks($tariff);
        $breakdown = [];
        $totalEnergyCost = 0;
        $totalUtilityCost = 0;

        // If energy is 0, we can't interpolate, so we use current block
        if ($kwh <= 0) {
            $current = $tariff->getCurrentPrices();
            $totalEnergyCost = 0;
            $totalUtilityCost = 0;
            $breakdown[] = [
                'block' => $current['block'],
                'energy_kwh' => 0,
                'rate' => $current['price_kwh'],
                'cost' => 0
            ];
        } else {
            // Logic: For each second of the session, determine which block it belongs to
            // For performance, we can find the intersection of the session interval with each block
            foreach ($blocks as $block) {
                $overlapSeconds = $this->getSecondsOverlap($start, $stop, $block['start'], $block['end']);
                if ($overlapSeconds > 0) {
                    $proportion = $overlapSeconds / $durationSeconds;
                    $blockKwh = $kwh * $proportion;
                    $blockPrice = $blockKwh * $block['price_kwh'];
                    $blockCost = $blockKwh * $block['cost_kwh'];

                    $totalEnergyCost += $blockPrice;
                    $totalUtilityCost += $blockCost;

                    $breakdown[] = [
                        'block' => $block['index'],
                        'energy_kwh' => round($blockKwh, 3),
                        'rate' => $block['price_kwh'],
                        'cost' => round($blockPrice, 2),
                        'seconds' => $overlapSeconds
                    ];
                }
            }

            // Fallback: If no blocks matched but there is energy consumption, use current price
            if ($totalEnergyCost <= 0 && $kwh > 0) {
                $current = $tariff->getCurrentPrices();
                $totalEnergyCost = $kwh * $current['price_kwh'];
                $breakdown = [
                    [
                        'block' => $current['block'],
                        'energy_kwh' => round($kwh, 3),
                        'rate' => $current['price_kwh'],
                        'cost' => round($totalEnergyCost, 2),
                        'seconds' => $durationSeconds
                    ]
                ];
                Log::info("Billing fallback used for Session #{$session->id} (No blocks matched). Using Block #{$current['block']} rate.");
            }
        }

        // Use the session fee from the session model if it's already been determined (skipped or charged)
        // Otherwise fallback to the tariff price
        $sessionFee = isset($session->session_fee) ? (float) $session->session_fee : (float) ($tariff->price_session ?? 0);

        // Fee Waiver Logic (v3.1)
        $settings = SystemSetting::get();
        if (($settings->waive_parking_fee_for_cards ?? false) && $session->rfidTag && !$session->rfidTag->is_virtual) {
            $sessionFee = 0;
        }

        // Minimum billing logic (User said "puede ser 1 sin problema")
        // We apply the minimum to the total energy cost if it's below the rate of 1kWh
        $minBillingKwh = 1.0;
        if ($kwh < $minBillingKwh && $kwh > 0) {
            // Adjust the energy cost to reflect at least 1kWh at the first encountered block rate
            $firstRate = $breakdown[0]['rate'] ?? $tariff->b1_price_kwh;
            $totalEnergyCost = $minBillingKwh * $firstRate;
        }

        // Time penalty (only on stop)
        $timeFee = 0;
        if ($stopTime && ($tariff->is_time_fee_enabled ?? true)) {
            $durationMin = $stop->diffInMinutes($start);
            $freeMin = (int) ($tariff->free_minutes ?? 0);
            $priceMin = (float) ($tariff->b1_price_min ?? 0); // Simplified: uses B1 price min
            $timeFee = max(0, $durationMin - $freeMin) * $priceMin;
        }

        // Fee & Discount Logic based on granular Tariff controls
        // Ensure the tag relationship is loaded to correctly identify modality (App vs Card)
        $tag = $session->rfidTag;
        if (!$tag && $session->rfid_tag_id) {
            $tag = \App\Models\RfidTag::find($session->rfid_tag_id);
        }

        $isPhysicalCard = !$tag || ($tag && !$tag->is_virtual);
        $isMobileApp = $tag && $tag->is_virtual;

        // Determine if Fee applies
        $shouldApplyFee = ($tariff->is_parking_fee_enabled ?? true) && (
            ($isPhysicalCard && ($tariff->apply_fee_to_cards ?? false)) ||
            ($isMobileApp && ($tariff->apply_fee_to_app ?? true))
        );

        if (!$shouldApplyFee) {
            $sessionFee = 0;
        }

        // Determine if Discount applies
        // NEW RULE: Discount applies ONLY if the Parking Fee is enabled for this modality
        $shouldApplyDiscount = $shouldApplyFee && ($tariff->discount_fixed_amount ?? 0) > 0 && (
            ($isPhysicalCard && ($tariff->apply_discount_to_cards ?? false)) ||
            ($isMobileApp && ($tariff->apply_discount_to_app ?? true))
        );

        $discountAmount = $shouldApplyDiscount ? (float) $tariff->discount_fixed_amount : 0;

        $actualDiscount = $shouldApplyDiscount ? min($discountAmount, $sessionFee) : 0;
        $subtotal = round($sessionFee + $totalEnergyCost + $timeFee, 2);
        $total = round($subtotal - $actualDiscount, 2);

        return [
            'total' => $total,
            'subtotal' => $subtotal,
            'discount_amount' => $actualDiscount,
            'session_fee' => round($sessionFee, 2),
            'time_fee' => round($timeFee, 2),
            'energy_cost' => round($totalEnergyCost, 2),
            'currency' => $tariff->currency ?? 'USD',
            'utility_cost' => round($totalUtilityCost, 2),
            'margin' => round($total - $totalUtilityCost, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Processes the initial session fee if the user has enough balance 
     * to also cover the minimum required energy (5kWh).
     */
    public function processInitialFee(ChargingSession $session): bool
    {
        return DB::transaction(function () use ($session) {
            // Refresh and lock session to prevent double processing
            $session = ChargingSession::where('id', $session->id)->lockForUpdate()->first();
            if (!$session)
                return false;

            $wallet = Wallet::where('user_id', $session->user_id)->first();
            $tag = $session->rfidTag;

            if (!$wallet || $wallet->is_postpaid) {
                return true;
            }

            // 1. Skip logic (Grace Period & Manual Tags)
            if ($this->shouldSkipSessionFee($session)) {
                $session->debited_amount = 0.0001; // Mark as "processed" with a tiny amount to avoid re-triggering
                $session->session_fee = 0;
                $session->save();
                return true;
            }

            $tariff = $session->tariff ?: Tariff::first();

            // Check granular fee toggle for cards vs app
            $isPhysicalCard = !$session->rfidTag || ($session->rfidTag && !$session->rfidTag->is_virtual);
            $isMobileApp = $session->rfidTag && $session->rfidTag->is_virtual;
            $shouldApplyFee = ($tariff->is_parking_fee_enabled ?? true) && (
                ($isPhysicalCard && ($tariff->apply_fee_to_cards ?? false)) ||
                ($isMobileApp && ($tariff->apply_fee_to_app ?? true))
            );

            if (!$shouldApplyFee) {
                $session->update([
                    'debited_amount' => 0.0001,
                    'session_fee' => 0
                ]);
                return true;
            }

            $currentPrices = $tariff->getCurrentPrices();
            $sessionFee = (float) ($currentPrices['price_session'] ?? 0);

            // We don't debit yet, we just check if it's possible to debit and have enough for 5kWh
            $minRequired = $sessionFee + (5.0 * $currentPrices['price_kwh']);

            $isVirtualTag = $tag && $tag->is_virtual;
            $availableBalance = ($tag && !$isVirtualTag) ? (float) $tag->balance : (float) $wallet->balance;

            if ($availableBalance < $minRequired) {
                return false;
            }

            // If we already debited the session fee (or more), don't do it again
            $initialFeeProcessed = ((float) $session->debited_amount > 0 || (float) $session->session_fee > 0);
            if ($initialFeeProcessed) {
                return true;
            }

            // Debit the session fee now
            if ($sessionFee > 0) {
                // Calculate net initial fee (Fee - Discount)
                $initialPricing = $this->calculateSessionCost($session, 0);
                $initialNetDebit = (float) $initialPricing['total'];
                Log::info("ProcessInitialFee for Session #{$session->id}: Fee={$initialPricing['subtotal']}, Discount={$initialPricing['discount_amount']}, NetDebit={$initialNetDebit}");

                $currentPrices = $tariff->getCurrentPrices();
                $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';

                DB::transaction(function () use ($tag, $isVirtualTag, $initialNetDebit, $wallet, $session, $sessionFee, $initialPricing, $tariff, $refCol, $currentPrices) {
                    if ($tag && !$isVirtualTag) {
                        $tag->decrement('balance', $initialNetDebit);
                    } else {
                        $wallet->decrement('balance', $initialNetDebit);
                    }

                    DB::table('wallet_transactions')->insert([
                        'wallet_id' => $wallet->id,
                        'user_id' => $session->user_id,
                        'type' => 'CHARGE',
                        'amount' => round(-$initialNetDebit, 2),
                        $refCol => (string) $session->transaction_id,
                        'description' => $this->formatTransactionDescription($session, $initialPricing),
                        'currency' => $currentPrices['currency'],
                        'balance_after' => ($tag && !$isVirtualTag) ? $tag->balance : $wallet->balance,
                        'status' => 'COMPLETED',
                        'metadata' => json_encode([
                            'billing_details' => [
                                'total_amount' => (float) $initialPricing['total'],
                                'parking_fee' => (float) $initialPricing['session_fee'],
                                'discount_amount' => (float) $initialPricing['discount_amount'],
                                'time_fee' => (float) $initialPricing['time_fee'],
                                'energy_kwh' => 0,
                                'energy_cost' => 0,
                                'breakdown' => $initialPricing['breakdown'],
                            ]
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $session->debited_amount = $initialNetDebit;
                    $session->session_fee = $sessionFee;
                    $session->discount_amount = $initialPricing['discount_amount'];
                    $session->applied_tariff_snapshot = $initialPricing['breakdown'] ? array_merge((array) $tariff->toArray(), ['billing_breakdown' => $initialPricing['breakdown']]) : $tariff->toArray();
                    $session->save();

                    // Force balance refresh
                    \Illuminate\Support\Facades\Cache::forget("wallet_balance_{$session->user_id}");
                });
            } else {
                // If fee is 0, mark as processed anyway
                $session->debited_amount = 0.0001;
                $session->session_fee = 0;
                $session->save();
            }

            return true;
        });
    }

    /**
     * Determine if the session fee should be skipped.
     */
    private function shouldSkipSessionFee(ChargingSession $session): bool
    {
        $tag = $session->rfidTag;
        $user = $session->user;

        // 1. Manual RFID Card Skip
        // We assume tags with specific products or physical tags without an assigned user are "manual"
        // Based on user request: "cuando el cobro se haga de una tarjeta rfid manual"
        if ($tag && !$tag->is_virtual) {
            // Check if the tag's product internal code is 'MANUAL-TAG' or similar
            // Or if the user explicitly wants ALL physical tags to be free of parking fee?
            // "cuando el cobro se haga de una tarjeta rfid manual igual no se le debe cobrar fee de parqueo solo el consumo"
            if ($tag->product && (str_contains(strtolower($tag->product->name), 'manual') || $tag->product->internal_code === 'MANUAL-TAG')) {
                return true;
            }
        }

        // 2. Grace Period
        // Check if there was a recently completed session for this user/tag
        $settings = SystemSetting::get();
        $graceMinutes = (int) ($settings->billing_grace_period ?? 3);

        $recentSession = ChargingSession::where('id', '!=', $session->id)
            ->where(function ($q) use ($session) {
                $q->where('user_id', $session->user_id)
                    ->orWhere('rfid_tag_id', $session->rfid_tag_id);
            })
            ->whereNotNull('stop_time')
            ->where('stop_time', '>=', now()->subMinutes($graceMinutes))
            ->exists();

        if ($recentSession) {
            Log::info("Skipping session fee due to grace period", ['session_id' => $session->id, 'user_id' => $session->user_id]);
            return true;
        }

        return false;
    }

    private function getTariffBlocks(Tariff $tariff): array
    {
        $blocks = [];
        for ($i = 1; $i <= 4; $i++) {
            if ($tariff->{"b{$i}_start"} && $tariff->{"b{$i}_end"}) {
                $blocks[] = [
                    'index' => $i,
                    'start' => $tariff->{"b{$i}_start"},
                    'end' => $tariff->{"b{$i}_end"},
                    'price_kwh' => (float) $tariff->{"b{$i}_price_kwh"},
                    'cost_kwh' => (float) $tariff->{"b{$i}_cost_kwh"},
                ];
            }
        }
        return $blocks;
    }

    private function getSecondsOverlap(Carbon $start, Carbon $stop, string $blockStart, string $blockEnd): int
    {
        $startLocal = $start->copy()->setTimezone('America/La_Paz');
        $stopLocal = $stop->copy()->setTimezone('America/La_Paz');
        $totalOverlap = 0;

        // Generate blocks for yesterday, today, and tomorrow to safely catch midnight wrap-arounds
        // and sessions that span across multiple days.
        $daysOffsets = [-1, 0, 1, 2]; // Up to 2 days ahead in case of long sessions

        foreach ($daysOffsets as $offset) {
            $baseDate = $startLocal->copy()->addDays($offset);

            $bStart = Carbon::parse($baseDate->format('Y-m-d ') . $blockStart, 'America/La_Paz');
            $bEnd = Carbon::parse($baseDate->format('Y-m-d ') . $blockEnd, 'America/La_Paz');

            // Handle blocks that wrap around midnight (e.g. 23:00 to 07:00)
            if ($bEnd->lt($bStart)) {
                $bEnd->addDay();
            }

            $overlapStart = $startLocal->copy()->max($bStart);
            $overlapEnd = $stopLocal->copy()->min($bEnd);

            if ($overlapStart->lt($overlapEnd)) {
                $totalOverlap += $overlapStart->diffInSeconds($overlapEnd);
            }
        }

        return $totalOverlap;
    }

    /**
     * Handles incremental debiting for active sessions.
     */
    public function processIncrementalDebit(ChargingSession $session, array $pricing): bool
    {
        $wallet = Wallet::where('user_id', $session->user_id)->first();
        $tag = $session->rfidTag;

        if (!$wallet || $wallet->is_postpaid) {
            return true;
        }

        $cost = $pricing['total'];
        $alreadyDebited = (float) ($session->debited_amount ?? 0);
        $delta = $cost - $alreadyDebited;

        if ($delta < 0.01) {
            return true; // No significant change
        }

        $isVirtualTag = $tag && $tag->is_virtual;
        $availableBalance = ($tag && !$isVirtualTag) ? (float) $tag->balance : (float) $wallet->balance;

        if ($availableBalance < $delta) {
            Log::warning("Insufficient balance for session", ['session' => $session->id, 'balance' => $availableBalance, 'required' => $delta]);
            return false; // Signal that session should stop
        }

        DB::transaction(function () use ($wallet, $session, $delta, $pricing, $tag) {
            $isVirtualTag = $tag && $tag->is_virtual;
            if ($tag && !$isVirtualTag) {
                $tag->balance -= $delta;
                $tag->save();
            } else {
                $wallet->balance -= $delta;
                $wallet->save();
            }

            $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';

            // Try to find an existing "CHARGE" transaction for this session to update it
            // instead of creating many small ones.
            $existingTx = WalletTransaction::where('user_id', $session->user_id)
                ->where('type', 'CHARGE')
                ->where($refCol, (string) $session->transaction_id)
                ->where('description', 'like', 'Carga #%')
                ->first();

            $newBalance = ($tag && !$isVirtualTag) ? $tag->balance : $wallet->balance;

            if ($existingTx) {
                $existingTx->update([
                    'amount' => $existingTx->amount - $delta,
                    'balance_after' => $newBalance,
                    'description' => $this->formatTransactionDescription($session, $pricing),
                    'metadata' => array_merge((array) ($existingTx->metadata ?? []), [
                        'billing_details' => [
                            'total_amount' => (float) $pricing['total'],
                            'parking_fee' => (float) $pricing['session_fee'],
                            'discount_amount' => (float) $pricing['discount_amount'],
                            'time_fee' => (float) $pricing['time_fee'],
                            'energy_kwh' => round((float) ($session->total_energy_kwh), 3),
                            'energy_cost' => (float) $pricing['energy_cost'],
                            'breakdown' => $pricing['breakdown'],
                        ]
                    ]),
                    'updated_at' => now(),
                ]);
            } else {
                $insertion = [
                    'wallet_id' => $wallet->id,
                    'user_id' => $session->user_id,
                    'type' => 'CHARGE',
                    'amount' => round(-$delta, 2),
                    $refCol => (string) $session->transaction_id,
                    'description' => $this->formatTransactionDescription($session, $pricing),
                    'currency' => $pricing['currency'],
                    'created_at' => now(),
                    'updated_at' => now(),
                    'metadata' => json_encode([
                        'billing_details' => [
                            'total_amount' => (float) $pricing['total'],
                            'parking_fee' => (float) $pricing['session_fee'],
                            'discount_amount' => (float) $pricing['discount_amount'],
                            'time_fee' => (float) $pricing['time_fee'],
                            'energy_kwh' => round((float) ($session->total_energy_kwh), 3),
                            'energy_cost' => (float) $pricing['energy_cost'],
                            'breakdown' => $pricing['breakdown'],
                        ]
                    ]),
                ];

                if (Schema::hasColumn('wallet_transactions', 'balance_after')) {
                    $insertion['balance_after'] = $newBalance;
                }
                if (Schema::hasColumn('wallet_transactions', 'status')) {
                    $insertion['status'] = 'COMPLETED';
                }

                DB::table('wallet_transactions')->insert($insertion);
            }

            $session->increment('debited_amount', $delta);
            $session->update([
                'total_cost' => $pricing['total'],
                'energy_cost' => $pricing['energy_cost'],
                'utility_cost' => $pricing['utility_cost'],
                'margin' => $pricing['margin'],
            ]);

            // Force balance refresh
            \Illuminate\Support\Facades\Cache::forget("wallet_balance_{$session->user_id}");
        });

        return true;
    }

    /**
     * Finalizes billing when a session stops.
     */
    public function finalizeBilling(ChargingSession $session)
    {
        if ($session->financial_locked_at) {
            return;
        }

        // LOCK IMMEDIATELY to avoid double invoicing from concurrent processes
        $session->update(['financial_locked_at' => now()]);
        $session->refresh();

        $pricing = $this->calculateSessionCost($session, $session->total_energy_kwh, $session->stop_time);

        $wallet = Wallet::where('user_id', $session->user_id)->first();
        $tag = $session->rfidTag;

        if ($wallet) {
            $alreadyDebited = (float) ($session->debited_amount ?? 0);
            $finalDelta = $pricing['total'] - $alreadyDebited;

            if (round($finalDelta, 2) != 0) {
                DB::transaction(function () use ($wallet, $session, $finalDelta, $pricing, $tag) {
                    $isVirtualTag = $tag && $tag->is_virtual;
                    if ($tag && !$isVirtualTag) {
                        $tag->balance -= $finalDelta;
                        $tag->save();
                    } else {
                        $wallet->balance -= $finalDelta;
                        $wallet->save();
                    }

                    $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';
                    $newBalance = ($tag && !$isVirtualTag) ? $tag->balance : $wallet->balance;

                    if ($finalDelta > 0) {
                        // Consolidate final delta into existing consumption transaction
                        $existingTx = WalletTransaction::where('user_id', $session->user_id)
                            ->where('type', 'CHARGE')
                            ->where($refCol, (string) $session->transaction_id)
                            ->where('description', 'like', 'Carga #%')
                            ->first();

                        if ($existingTx) {
                            $existingTx->update([
                                'amount' => $existingTx->amount - $finalDelta,
                                'balance_after' => $newBalance,
                                'description' => $this->formatTransactionDescription($session, $pricing),
                                'metadata' => array_merge((array) ($existingTx->metadata ?? []), [
                                    'billing_details' => [
                                        'total_amount' => (float) $pricing['total'],
                                        'parking_fee' => (float) $pricing['session_fee'],
                                        'discount_amount' => (float) $pricing['discount_amount'],
                                        'time_fee' => (float) $pricing['time_fee'],
                                        'energy_kwh' => round((float) ($session->total_energy_kwh), 3),
                                        'energy_cost' => (float) $pricing['energy_cost'],
                                        'breakdown' => $pricing['breakdown'],
                                    ]
                                ]),
                                'updated_at' => now(),
                            ]);
                        } else {
                            DB::table('wallet_transactions')->insert([
                                'wallet_id' => $wallet->id,
                                'user_id' => $session->user_id,
                                'type' => 'CHARGE',
                                'amount' => round(-$finalDelta, 2),
                                $refCol => (string) $session->transaction_id,
                                'description' => $this->formatTransactionDescription($session, $pricing),
                                'currency' => $pricing['currency'],
                                'balance_after' => $newBalance,
                                'status' => 'COMPLETED',
                                'metadata' => json_encode([
                                    'billing_details' => [
                                        'total_amount' => (float) $pricing['total'],
                                        'parking_fee' => (float) $pricing['session_fee'],
                                        'discount_amount' => (float) $pricing['discount_amount'],
                                        'time_fee' => (float) $pricing['time_fee'],
                                        'energy_kwh' => round((float) ($session->total_energy_kwh), 3),
                                        'energy_cost' => (float) $pricing['energy_cost'],
                                        'breakdown' => $pricing['breakdown'],
                                    ]
                                ]),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    } else {
                        // It's a REFUND (finalDelta is negative)
                        DB::table('wallet_transactions')->insert([
                            'wallet_id' => $wallet->id,
                            'user_id' => $session->user_id,
                            'type' => 'CREDIT',
                            'amount' => round(abs($finalDelta), 2),
                            $refCol => (string) $session->transaction_id,
                            'description' => "Reembolso Diferencia Carga #" . $session->transaction_id . ($session->rfidTag ? " (Tarjeta: {$session->rfidTag->tag_code})" : ""),
                            'currency' => $pricing['currency'],
                            'balance_after' => $newBalance,
                            'status' => 'COMPLETED',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $session->increment('debited_amount', $finalDelta);
                });
            } else {
                $session->debited_amount = $pricing['total'];
                $session->save();
            }
        }

        $session->update([
            'total_cost' => $pricing['total'],
            'discount_amount' => $pricing['discount_amount'],
            'session_fee' => $pricing['session_fee'],
            'time_fee' => $pricing['time_fee'],
            'energy_cost' => $pricing['energy_cost'],
            'utility_cost' => $pricing['utility_cost'],
            'margin' => $pricing['margin'],
            'applied_tariff_snapshot' => array_merge(
                (array) $session->applied_tariff_snapshot,
                [
                    'billing_breakdown' => $pricing['breakdown'],
                    'discount_amount' => $pricing['discount_amount'],
                    'subtotal' => $pricing['subtotal'],
                ]
            ),
        ]);

        // Trigger invoicing based on System Policy
        $settings = SystemSetting::get();
        $isPostpaid = $wallet?->is_postpaid ?? false;

        if ($session->user_id && $pricing['total'] > 0) {
            // Only invoice at session end if policy is NOT 'recharge' 
            // OR if the user is postpaid (as they don't do pre-recharges).
            if ($settings->invoicing_policy !== 'recharge' || $isPostpaid) {
                $this->triggerInvoice($session);
            } else {
                Log::info("Session #{$session->id} finished. Skipping session-end invoice due to 'recharge' policy.");
            }
        } else {
            Log::info("Session #{$session->id} has no user or cost 0. Skipping Libelula invoice.");
        }

        // Force balance refresh in Redis/App
        if ($session->user_id) {
            $wallet = Wallet::where('user_id', $session->user_id)->first();
            if ($wallet) {
                \Illuminate\Support\Facades\Cache::forget("wallet_balance_{$session->user_id}");
            }
        }
    }

    public function triggerInvoice(ChargingSession $session)
    {
        $libService = app(\App\Services\LibelulaPaymentService::class);
        try {
            Log::info("Triggering invoice for Session #{$session->id}", ['user_id' => $session->user_id]);

            $wallet = Wallet::where('user_id', $session->user_id)->first();
            if (!$wallet) {
                Log::warning("Cannot trigger invoice: User has no wallet", ['user_id' => $session->user_id]);
                return;
            }

            if (!$session->user || empty($session->user->billing_document)) {
                Log::warning("Cannot trigger invoice: User has no billing document (NIT/CI)", ['user_id' => $session->user_id]);
                return;
            }

            // Find the wallet transaction to link it
            $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';
            $walletTx = WalletTransaction::where('user_id', $session->user_id)
                ->where('type', 'CHARGE')
                ->where($refCol, (string) $session->transaction_id)
                ->first();

            $settings = SystemSetting::get();
            $lineItems = [];

            // 1. Energy Components (Breakdown by Blocks)
            $energyProduct = Product::find($settings->product_energy_id)
                ?? Product::where('internal_code', 'ENERGY-SVC')->first();
            $energyName = $energyProduct?->name ?? 'Consumo de Energía';

            $breakdown = $session->applied_tariff_snapshot['billing_breakdown'] ?? [];

            if (!empty($breakdown)) {
                foreach ($breakdown as $item) {
                    $cost = (float) ($item['cost'] ?? 0);
                    $kwh = (float) ($item['energy_kwh'] ?? 0);
                    if ($cost > 0 || $kwh > 0) {
                        $blockIdx = $item['block'] ?? '?';
                        $rate = round((float) ($item['rate'] ?? 0), 2);

                        $lineItems[] = [
                            'concepto' => "{$energyName} (Bloque {$blockIdx})",
                            'cantidad' => 1,
                            'costo_unitario' => round($cost, 2),
                            'descuento_unitario' => 0,
                            'detalle' => "Uso en Bloque Horario #{$blockIdx} kWh " . round($kwh, 2) . " x " . number_format($rate, 2) . " = " . number_format($cost, 2) . " BoB",
                            'codigo_producto' => $energyProduct?->siat_product_code ?? '10',
                        ];
                        Log::info("Added Energy Line for Session #{$session->id}: " . json_encode(end($lineItems)));
                    }
                }
            } elseif ($session->energy_cost > 0) {
                // Fallback to single energy line
                $kwh = round((float) $session->total_energy_kwh, 2);
                $rate = round((float) $session->rate_kwh, 2);
                $cost = round((float) $session->energy_cost, 2);
                $lineItems[] = [
                    'concepto' => "{$energyName}",
                    'cantidad' => 1,
                    'costo_unitario' => $cost,
                    'descuento_unitario' => 0,
                    'detalle' => "Carga kWh " . round($kwh, 2) . " x " . number_format($rate, 2) . " = " . number_format($cost, 2) . " BoB",
                    'codigo_producto' => $energyProduct?->siat_product_code ?? '10',
                ];
            }

            // 2. Initial Session Fee (Parking)
            if ($session->session_fee > 0) {
                $connProduct = Product::find($settings->product_connection_id)
                    ?? $tariff?->connectionProduct
                    ?? Product::where('internal_code', 'CONN-FEE')->first();

                $lineItems[] = [
                    'concepto' => $connProduct?->name ?? "Cargo por Conexión / Inicio de Sesión (Tarifa Plana)",
                    'cantidad' => 1,
                    'costo_unitario' => round($session->session_fee, 2),
                    'descuento_unitario' => 0,
                    'detalle' => "Servicio de Conexión",
                    'codigo_producto' => $connProduct?->siat_product_code ?? '5',
                ];
            }

            // 3. Time Penalty Fee
            if ($session->time_fee > 0) {
                $timeProduct = Product::find($settings->product_penalty_id)
                    ?? $tariff?->timeProduct
                    ?? Product::where('internal_code', 'TIME-PENALTY')->first();

                $lineItems[] = [
                    'concepto' => "{$timeProduct?->name} - Recargo por Tiempo Excedido",
                    'cantidad' => 1,
                    'costo_unitario' => round($session->time_fee, 2),
                    'descuento_unitario' => 0,
                    'detalle' => "Penalty fee por ocupación excesiva",
                    'codigo_producto' => $timeProduct?->siat_product_code ?? '1',
                ];
            }

            // Fallback safety
            if (empty($lineItems)) {
                $lineItems[] = [
                    'concepto' => "Servicio de Carga #" . $session->transaction_id,
                    'cantidad' => 1,
                    'costo_unitario' => max(0.01, round($session->total_cost, 2)),
                    'descuento_unitario' => 0,
                    'detalle' => "Consumo de Energía",
                    'codigo_producto' => '1',
                ];
            }

            Log::info("Triggering invoice for Session #{$session->id}");

            $subtotal = (float) ($session->applied_tariff_snapshot['subtotal'] ?? ($session->total_cost + ($session->discount_amount ?? 0)));
            $discount = (float) ($session->applied_tariff_snapshot['discount_amount'] ?? ($session->discount_amount ?? 0));

            // Final safety check to avoid negative invoices in Libelula
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }

            // Ensure subtotal is not zero for Libelula
            $amountToSend = max(0.01, round($subtotal, 2));

            $libResponse = $libService->createPayment($wallet, $amountToSend, "Consumo Energía #{$session->transaction_id}", [
                'transaction_id' => $walletTx?->id,
                'emite_factura' => true,
                'internal_usage_tx' => true,
                'session_id' => $session->id,
                'identificador' => "SES-{$session->transaction_id}",
                'line_items' => $lineItems,
                'codigo_tipo_documento' => $session->user?->billing_doc_type ?? 'CI',
                'razon_social' => $session->user->billing_razon_social,
                'documento' => $session->user->billing_document,
                'complemento' => $session->user->billing_complement,
            ], true, $discount);

            Log::info("Libélula response for Session #{$session->id}", ['success' => $libResponse['success'] ?? false, 'url' => $libResponse['payment_url'] ?? null]);

            $urlToSave = $libResponse['invoice_url'] ?? $libResponse['payment_url'];

            if ($libResponse['success'] && !empty($urlToSave)) {
                $session->update([
                    'invoice_url' => $urlToSave,
                    'external_payment_id' => $libResponse['transaction_id'] ?? null
                ]);

                // Explicitly save the object attributes too in case update() doesn't refresh the local instance used elsewhere
                $session->invoice_url = $urlToSave;
                $session->external_payment_id = $libResponse['transaction_id'] ?? null;
                $session->save();

                // Also update the wallet transaction so it appears in the mobile app history
                $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';
                WalletTransaction::where('user_id', $session->user_id)
                    ->where('type', 'CHARGE')
                    ->where($refCol, (string) $session->transaction_id)
                    ->update(['invoice_url' => $urlToSave]);

                Log::info("Invoice URL saved for Session #{$session->id} and related WalletTransaction.");
            }
        } catch (\Throwable $ex) {
            Log::error("Failed to trigger invoice for Session #{$session->id}", ['error' => $ex->getMessage(), 'trace' => $ex->getTraceAsString()]);
        }
    }

    /**
     * Formats a detailed description for the wallet transaction.
     */
    private function formatTransactionDescription(ChargingSession $session, array $pricing): string
    {
        $kwh = round((float) ($session->total_energy_kwh ?? 0), 2);
        $parts = ["Carga #{$session->transaction_id} ({$kwh} kWh)"];

        if ($pricing['discount_amount'] > 0) {
            $parts[] = "Subt: " . number_format($pricing['subtotal'], 2);
            $parts[] = "Desc: -" . number_format($pricing['discount_amount'], 2);
        }

        $parts[] = "Total: " . number_format($pricing['total'], 2) . " " . ($pricing['currency'] ?? 'BOB');

        if ($session->rfidTag) {
            $parts[] = "Tag: " . $session->rfidTag->tag_code;
        }

        return implode(" | ", $parts);
    }
}

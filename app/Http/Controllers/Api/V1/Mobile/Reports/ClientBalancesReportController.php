<?php

namespace App\Http\Controllers\Api\V1\Mobile\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Mobile\Reports\Traits\HasReportFilters;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientBalancesReportController extends Controller
{
    use HasReportFilters;

    /**
     * Endpoint: Clientes y Saldos (Recargas vs Consumos)
     */
    public function getClientBalances(Request $request)
    {
        $cacheKey = 'report_client_balances_' . md5(json_encode($request->all()));

        $response = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($request) {
            $startDate = $request->query('startDate');
            $endDate = $request->query('endDate');
            $month = $request->query('month');

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

        $refundsQuery = DB::table('wallet_transactions')
            ->whereIn('type', ['REFUND', 'CREDIT'])
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

        $sessionsQuery = DB::table('charging_sessions')
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

        if ($month) {
            $carbonMonth = Carbon::parse($month . '-01');
            $startDate = $carbonMonth->startOfMonth()->toDateString();
            $endDate = $carbonMonth->endOfMonth()->toDateString();
        }

        $localTimeExpr = 'DATE_SUB(wallet_transactions.created_at, INTERVAL 4 HOUR)';
        $sessTimeExpr = 'DATE_SUB(charging_sessions.start_time, INTERVAL 4 HOUR)';

        if ($startDate) {
            $rechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
            $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
            $refundsQuery->whereRaw("DATE($localTimeExpr) >= ?", [$startDate]);
            $sessionsQuery->whereRaw("DATE($sessTimeExpr) >= ?", [$startDate]);
        }
        if ($endDate) {
            $rechargesQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
            $excludedRechargesQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
            $refundsQuery->whereRaw("DATE($localTimeExpr) <= ?", [$endDate]);
            $sessionsQuery->whereRaw("DATE($sessTimeExpr) <= ?", [$endDate]);
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
            ->where(function ($q) {
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
            ->where(function ($q) {
                $q->where(function ($sub) {
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
            ->whereNotExists(function ($query) {
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
            ->where(function ($q) {
                $q->whereNull('users.billing_document')
                    ->orWhere('users.billing_document', '')
                    ->orWhere('users.billing_document', '0')
                    ->orWhere('users.name', 'LIKE', 'Usuario RFID%')
                    ->orWhere('users.email', 'LIKE', '%@evce.temp');
            });

        $topConsumers = $topConsumersQuery->get()->map($mapUserBalances);
        $excludedConsumers = $excludedConsumersQuery->get()->map($mapUserBalances);

            return [
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
            ];
        });

        return response()->json($response);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Mobile\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Mobile\Reports\Traits\HasReportFilters;
use App\Models\ChargingSession;
use App\Models\Company;
use App\Models\RfidTag;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RfidDealershipsReportController extends Controller
{
    use HasReportFilters;

    /**
     * Endpoint: RFID / Concesionarias (JAC, XPENG, CHANGAN, MG)
     */
    public function getRfidDealerships(Request $request)
    {
        $cacheKey = 'report_rfid_dealerships_' . md5(json_encode($request->all()));

        $response = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($request) {
            $cardType = $request->query('cardType', 'physical');
            $companyId = $request->query('companyId');

            $tagsQuery = RfidTag::with(['user.vehicles', 'company']);
            if ($cardType === 'physical') {
                $tagsQuery->where('is_virtual', false);
            } elseif ($cardType === 'virtual') {
                $tagsQuery->where('is_virtual', true);
            }

        if ($companyId) {
            $tagsQuery->where('company_id', $companyId);
        }

        $allTags = $tagsQuery->get();
        $totalCards = $allTags->count();

        $rfidSessionsQuery = ChargingSession::whereNotNull('charging_sessions.rfid_tag_id');
        $this->applyFilters($rfidSessionsQuery, $request);

        $usedTagIds = (clone $rfidSessionsQuery)->distinct()->pluck('rfid_tag_id')->toArray();
        $usedCardsCount = count(array_intersect($allTags->pluck('id')->toArray(), $usedTagIds));
        $unusedCardsCount = $totalCards - $usedCardsCount;

        $rfidStats = (clone $rfidSessionsQuery)
            ->selectRaw('
                rfid_tag_id,
                COUNT(id) as sessions_count,
                SUM(total_energy_kwh) as energy_kwh,
                SUM(total_cost) as revenue_bob
            ')
            ->groupBy('rfid_tag_id')
            ->get()
            ->keyBy('rfid_tag_id');

        $totalCreditConsumed = $rfidStats->sum('revenue_bob');
        $totalEnergyConsumed = $rfidStats->sum('energy_kwh');

        $targetDealerships = ['JAC', 'XPENG', 'CHANGAN', 'MG'];
        $dealershipSummary = [];

        foreach ($targetDealerships as $brand) {
            // Find company by name
            $company = Company::where('name', 'LIKE', '%' . $brand . '%')->first();
            if ($company) {
                $brandTags = $allTags->where('company_id', $company->id);
            } else {
                $brandTags = collect();
            }
            $brandTagIds = $brandTags->pluck('id')->toArray();

            $sessionsForBrand = (clone $rfidSessionsQuery)->whereIn('rfid_tag_id', $brandTagIds);

            $dealershipSummary[] = [
                'brand' => $brand,
                'cards_delivered' => $brandTags->count(),
                'cards_active' => count(array_intersect($brandTagIds, $usedTagIds)),
                'sessions_count' => (clone $sessionsForBrand)->count(),
                'energy_kwh' => round((float) (clone $sessionsForBrand)->sum('total_energy_kwh'), 1),
                'revenue_bob' => round((float) (clone $sessionsForBrand)->sum('total_cost'), 2),
            ];
        }

        $userWalletRecharges = WalletTransaction::where('type', 'RECHARGE')
            ->whereIn('status', ['COMPLETED', 'Completed'])
            ->selectRaw('user_id, SUM(amount) as total_charged')
            ->groupBy('user_id')
            ->pluck('total_charged', 'user_id');

        $topRfidDetails = $allTags
            ->map(function ($tag) use ($rfidStats, $userWalletRecharges) {
                $stat = $rfidStats->get($tag->id);

                // Get vehicle brand(s) of user
                $brands = 'N/A';
                if ($tag->user && $tag->user->vehicles) {
                    $brands = $tag->user->vehicles->pluck('brand')->unique()->implode(', ');
                }
                if (empty($brands)) {
                    $brands = 'N/A';
                }

                // Get the last session date for this RFID
                $lastSessionDate = ChargingSession::where('rfid_tag_id', $tag->id)
                    ->orderBy('start_time', 'desc')
                    ->value('start_time');

                // Format the local date for the last session
                $lastSessionLocal = $lastSessionDate ? Carbon::parse($lastSessionDate)->setTimezone('America/La_Paz')->toDateTimeString() : 'Nunca';

                $totalCharged = 0.0;
                if ($tag->is_virtual) {
                    $totalCharged = (float) ($tag->user_id ? ($userWalletRecharges->get($tag->user_id) ?? 0) : 0);
                } else {
                    $totalCharged = (float) $tag->balance + (float) ($stat ? ($stat->revenue_bob ?? 0) : 0);
                }

                return [
                    'id' => $tag->id,
                    'tag_code' => $tag->tag_code,
                    'user_name' => $tag->user?->name ?? 'Sin Asignar',
                    'brand' => $brands,
                    'company_name' => $tag->company?->name ?? 'Particular',
                    'is_virtual' => (bool) $tag->is_virtual,
                    'sessions_count' => $stat ? ($stat->sessions_count ?? 0) : 0,
                    'energy_kwh' => round((float) ($stat ? ($stat->energy_kwh ?? 0) : 0), 1),
                    'revenue_bob' => round((float) ($stat ? ($stat->revenue_bob ?? 0) : 0), 2),
                    'current_balance' => (float) $tag->balance,
                    'total_charged' => round($totalCharged, 2),
                    'last_session_time' => $lastSessionLocal,
                    'fecha_ajustada' => $lastSessionDate ? true : false,
                ];
            })
            ->sortByDesc('sessions_count')
            ->values();

            return [
                'success' => true,
                'rfid_overview' => [
                    'total_cards' => $totalCards,
                    'used_cards' => $usedCardsCount,
                    'unused_cards' => $unusedCardsCount,
                    'total_credit_consumed_bob' => round((float) $totalCreditConsumed, 2),
                    'total_energy_consumed_kwh' => round((float) $totalEnergyConsumed, 1),
                ],
                'dealerships_breakdown' => $dealershipSummary,
                'top_rfid_tags' => $topRfidDetails,
            ];
        });

        return response()->json($response);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Mobile\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Mobile\Reports\Traits\HasReportFilters;
use App\Models\ChargingSession;
use App\Models\Tariff;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AetnBillingReportController extends Controller
{
    use HasReportFilters;

    /**
     * Endpoint: Facturación y Energía AETN (Bloques Tarifarios)
     */
    public function getAetnBilling(Request $request)
    {
        try {
            $cacheKey = 'report_aetn_billing_' . md5(json_encode($request->all()));

            $response = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($request) {
                $hasCustomRates = $request->has('rateBajo') || $request->has('rateMedio') || $request->has('rateAlto');

                // Dynamically resolve reference tariff strictly by valid_from and valid_until for requested month/period
                $filterMonth = $request->query('month');
                $startDate = $request->query('startDate');
                if (!$filterMonth && $startDate) {
                    $filterMonth = substr($startDate, 0, 7);
                }
                if (!$filterMonth) {
                    $filterMonth = now()->setTimezone('America/La_Paz')->format('Y-m');
                }

                $mStart = $filterMonth . '-01 00:00:00';
                $mEnd = date('Y-m-t 23:59:59', strtotime($filterMonth . '-01'));

                $activeTariff = Tariff::where(function ($q) use ($mEnd) {
                    $q->whereNull('valid_from')
                        ->orWhere('valid_from', '<=', $mEnd);
                })
                    ->where(function ($q) use ($mStart) {
                        $q->whereNull('valid_until')
                            ->orWhere('valid_until', '>=', $mStart);
                    })
                    ->orderBy('valid_from', 'desc')
                    ->first();

                $defaultBajo = $activeTariff && $activeTariff->b1_price_kwh !== null ? (float) $activeTariff->b1_price_kwh : 0.0;
                $defaultMedio = $activeTariff && $activeTariff->b2_price_kwh !== null ? (float) $activeTariff->b2_price_kwh : 0.0;
                $defaultAlto = $activeTariff && $activeTariff->b3_price_kwh !== null ? (float) $activeTariff->b3_price_kwh : 0.0;

                $reqBajo = (float) $request->query('rateBajo', $defaultBajo);
                $reqMedio = (float) $request->query('rateMedio', $defaultMedio);
                $reqAlto = (float) $request->query('rateAlto', $defaultAlto);

                $query = ChargingSession::query()->where('charging_sessions.status', 'Completed');
                $this->applyFilters($query, $request);

                // Fetching all completed sessions matching filters. To optimize performance, we select only necessary fields.
                $sessions = $query->with('station')
                    ->select('id', 'start_time', 'stop_time', 'total_energy_kwh', 'applied_tariff_snapshot', 'tariff_id', 'station_id')
                    ->get();

                $billingService = new BillingService();

                $monthlyData = [];

                foreach ($sessions as $session) {
                    $localStart = Carbon::parse($session->start_time)->setTimezone('America/La_Paz');
                    $month = $localStart->format('Y-m');

                    if (!isset($monthlyData[$month])) {
                        $monthlyData[$month] = [
                            'kwh_bajo' => 0.0,
                            'kwh_medio' => 0.0,
                            'kwh_alto' => 0.0,
                            'total_kwh' => 0.0,
                            'tariffs' => [],
                        ];
                    }

                    // Retrieve breakdown
                    $breakdown = null;
                    if ($session->applied_tariff_snapshot) {
                        $snapshot = is_array($session->applied_tariff_snapshot)
                            ? $session->applied_tariff_snapshot
                            : json_decode($session->applied_tariff_snapshot, true);

                        $breakdown = $snapshot['billing_breakdown'] ?? null;
                    }

                    if (!$breakdown) {
                        // Calculate dynamically
                        try {
                            $pricing = $billingService->calculateSessionCost($session, (float) $session->total_energy_kwh, $session->stop_time);
                            $breakdown = $pricing['breakdown'] ?? [];
                        } catch (\Exception $e) {
                            $breakdown = [];
                        }
                    }

                    $sessionKwhBajo = 0.0;
                    $sessionKwhMedio = 0.0;
                    $sessionKwhAlto = 0.0;

                    foreach ($breakdown as $item) {
                        $blockIdx = (int) ($item['block'] ?? 1);
                        $kwh = (float) ($item['energy_kwh'] ?? 0);
                        if ($blockIdx === 1) {
                            $sessionKwhBajo += $kwh;
                        } elseif ($blockIdx === 2 || $blockIdx === 4) {
                            $sessionKwhMedio += $kwh;
                        } elseif ($blockIdx === 3) {
                            $sessionKwhAlto += $kwh;
                        } else {
                            // Default fallback for any block index >= 5
                            $sessionKwhBajo += $kwh;
                        }
                    }

                    // Fallback to legacy hour ranges if breakdown is empty and session had energy
                    $totalSessionKwh = (float) $session->total_energy_kwh;
                    if ($sessionKwhBajo + $sessionKwhMedio + $sessionKwhAlto <= 0 && $totalSessionKwh > 0) {
                        $hour = (int) $localStart->format('H');
                        if ($hour >= 23 || $hour < 7) {
                            $sessionKwhBajo = $totalSessionKwh;
                        } elseif ($hour >= 18 && $hour < 21) {
                            $sessionKwhAlto = $totalSessionKwh;
                        } else {
                            $sessionKwhMedio = $totalSessionKwh;
                        }
                    }

                    $monthlyData[$month]['kwh_bajo'] += $sessionKwhBajo;
                    $monthlyData[$month]['kwh_medio'] += $sessionKwhMedio;
                    $monthlyData[$month]['kwh_alto'] += $sessionKwhAlto;
                    $monthlyData[$month]['total_kwh'] += $totalSessionKwh;

                    if ($session->tariff_id) {
                        $monthlyData[$month]['tariffs'][] = $session->tariff_id;
                    }
                }

                ksort($monthlyData);

                $resultMonthly = [];
                $totalKwhBajo = 0;
                $totalKwhMedio = 0;
                $totalKwhAlto = 0;
                $grandTotalKwh = 0;
                $totalGrossRevenue = 0;
                $latestRates = ['bajo' => $reqBajo, 'medio' => $reqMedio, 'alto' => $reqAlto];

                foreach ($monthlyData as $month => $data) {
                    $kBajo = round($data['kwh_bajo'], 1);
                    $kMedio = round($data['kwh_medio'], 1);
                    $kAlto = round($data['kwh_alto'], 1);
                    $kTotal = round($data['total_kwh'], 1);

                    if ($hasCustomRates) {
                        $mBajo = $reqBajo;
                        $mMedio = $reqMedio;
                        $mAlto = $reqAlto;
                    } else {
                        $monthStart = $month . '-01 00:00:00';
                        $monthEnd = date('Y-m-t 23:59:59', strtotime($month . '-01'));

                        $tariff = Tariff::where(function ($q) use ($monthEnd) {
                            $q->whereNull('valid_from')
                                ->orWhere('valid_from', '<=', $monthEnd);
                        })
                            ->where(function ($q) use ($monthStart) {
                                $q->whereNull('valid_until')
                                    ->orWhere('valid_until', '>=', $monthStart);
                            })
                            ->orderBy('valid_from', 'desc')
                            ->first();

                        $mBajo = $tariff && $tariff->b1_price_kwh !== null ? (float) $tariff->b1_price_kwh : 0.0;
                        $mMedio = $tariff && $tariff->b2_price_kwh !== null ? (float) $tariff->b2_price_kwh : 0.0;
                        $mAlto = $tariff && $tariff->b3_price_kwh !== null ? (float) $tariff->b3_price_kwh : 0.0;
                    }

                    $latestRates = ['bajo' => $mBajo, 'medio' => $mMedio, 'alto' => $mAlto];

                    $rBajo = round($kBajo * $mBajo, 2);
                    $rMedio = round($kMedio * $mMedio, 2);
                    $rAlto = round($kAlto * $mAlto, 2);
                    $rTotal = round($rBajo + $rMedio + $rAlto, 2);

                    $totalKwhBajo += $kBajo;
                    $totalKwhMedio += $kMedio;
                    $totalKwhAlto += $kAlto;
                    $grandTotalKwh += $kTotal;
                    $totalGrossRevenue += $rTotal;

                    $resultMonthly[] = [
                        'month' => $month,
                        'bloque_bajo' => ['kwh' => $kBajo, 'rate_aetn' => $mBajo, 'gross_revenue' => $rBajo],
                        'bloque_medio' => ['kwh' => $kMedio, 'rate_aetn' => $mMedio, 'gross_revenue' => $rMedio],
                        'bloque_alto' => ['kwh' => $kAlto, 'rate_aetn' => $mAlto, 'gross_revenue' => $rAlto],
                        'total_kwh' => $kTotal,
                        'total_gross_revenue' => $rTotal,
                    ];
                }

                // Use active tariff resolved for the period to get current hour definitions
                $defTariff = $activeTariff ?: (Tariff::where('name', 'LIKE', '%Estándar%')
                    ->orWhere('name', 'LIKE', '%Estandar%')
                    ->first() ?: Tariff::first());

                // Standardize formats for B1, B2, B3 hours
                $formatTime = function ($timeStr, $default) {
                    if (!$timeStr)
                        return $default;
                    return substr($timeStr, 0, 5); // Take "HH:MM" from "HH:MM:SS"
                };

                $bajoTime = $defTariff && $defTariff->b1_start && $defTariff->b1_end
                    ? ($formatTime($defTariff->b1_start, '23:00') . ' - ' . $formatTime($defTariff->b1_end, '07:00'))
                    : "23:00 - 07:00";

                $altoTime = $defTariff && $defTariff->b3_start && $defTariff->b3_end
                    ? ($formatTime($defTariff->b3_start, '18:00') . ' - ' . $formatTime($defTariff->b3_end, '21:00'))
                    : "18:00 - 21:00";

                $medioTime = "07:00 - 18:00, 21:00 - 23:00";
                if ($defTariff && $defTariff->b2_start && $defTariff->b2_end) {
                    $medioTime = $formatTime($defTariff->b2_start, '07:00') . ' - ' . $formatTime($defTariff->b2_end, '18:00');
                    if ($defTariff->b4_start && $defTariff->b4_end) {
                        $medioTime .= ', ' . $formatTime($defTariff->b4_start, '21:00') . ' - ' . $formatTime($defTariff->b4_end, '23:00');
                    }
                }

                $blockDefinitions = [
                    'bajo' => $bajoTime,
                    'medio' => $medioTime,
                    'alto' => $altoTime,
                ];

                $totalKwhBajoRounded = round($totalKwhBajo, 1);
                $totalKwhMedioRounded = round($totalKwhMedio, 1);
                $totalKwhAltoRounded = round($totalKwhAlto, 1);
                $grandTotalKwhRounded = round($totalKwhBajoRounded + $totalKwhMedioRounded + $totalKwhAltoRounded, 1);

                return [
                    'success' => true,
                    'has_data' => count($resultMonthly) > 0 && $grandTotalKwhRounded > 0,
                    'rates_applied' => $latestRates,
                    'summary_totals' => [
                        'total_sessions' => $sessions->count(),
                        'grand_total_kwh' => $grandTotalKwhRounded,
                        'total_kwh_alto' => $totalKwhAltoRounded,
                        'total_kwh_medio' => $totalKwhMedioRounded,
                        'total_kwh_bajo' => $totalKwhBajoRounded,
                        'grand_total_gross_revenue_bob' => round($totalGrossRevenue, 2),
                    ],
                    'monthly_details' => $resultMonthly,
                    'block_definitions' => $blockDefinitions,
                ];
            });

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('getAetnBilling Error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

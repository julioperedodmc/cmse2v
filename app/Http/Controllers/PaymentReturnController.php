<?php

namespace App\Http\Controllers;

use App\Models\LibelulaApiLog;
use App\Services\LibelulaPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * callback_url of every Libélula debt. Two kinds of requests arrive here:
 *  - Libélula's server notifying a payment (its callback), and
 *  - the payer's browser, redirected after paying, which must be sent back to the app.
 *
 * The URL is public and its parameters can be forged, so a callback never credits a
 * recharge by itself: it only identifies the pending recharge, which is then confirmed
 * with Libélula (verifyStatus) once.
 */
class PaymentReturnController extends Controller
{
    private const CALLBACK_LOCK_SECONDS = 30;

    public function __invoke(Request $request, LibelulaPaymentService $libelula)
    {
        $params = array_merge($request->query(), $request->post());
        $txId = (int) ($params['tx_id'] ?? 0);

        if ($txId > 0 && array_key_exists('error', $params)) {
            $this->handleCallback($request, $params, $txId, $libelula);
        }

        return response($this->redirectPage($txId));
    }

    private function handleCallback(Request $request, array $params, int $txId, LibelulaPaymentService $libelula): void
    {
        $result = 'ignored';

        try {
            $tx = DB::table('wallet_transactions')
                ->where('id', $txId)
                ->where('payment_method', 'like', 'LIBELULA%')
                ->first();

            if (!$tx) {
                $result = 'transaction_not_found';
            } elseif (strtoupper((string) ($tx->status ?? '')) !== 'PENDING') {
                $result = 'already_' . strtolower((string) $tx->status);
            } elseif ((string) $params['error'] !== '0') {
                $result = 'payment_error_reported';
            } elseif (!$this->referenceMatches($tx, $params['transaction_id'] ?? null)) {
                $result = 'reference_mismatch';
            } elseif (!Cache::add("libelula:callback:{$txId}", true, self::CALLBACK_LOCK_SECONDS)) {
                $result = 'recently_checked';
            } else {
                $result = $libelula->verifyStatus($txId) ? 'confirmed' : 'not_confirmed';
            }
        } catch (\Throwable $e) {
            $result = 'exception';
            Log::error('Libelula callback error', ['tx_id' => $txId, 'error' => $e->getMessage()]);
        }

        try {
            LibelulaApiLog::create([
                'endpoint' => 'Callback-Return',
                'method' => $request->method(),
                'request_payload' => [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'params' => $params,
                ],
                'response_payload' => ['tx_id' => $txId, 'result' => $result],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Libelula callback log failed', ['error' => $e->getMessage()]);
        }

        Log::info('Libelula callback', ['tx_id' => $txId, 'result' => $result, 'ip' => $request->ip()]);
    }

    /**
     * Libélula echoes our identificador as transaction_id. When it is present it must match
     * the recharge, so a request cannot point one payment's data at another recharge.
     */
    private function referenceMatches(object $tx, $reference): bool
    {
        if ($reference === null || $reference === '') {
            return true;
        }

        $reference = (string) $reference;
        $known = array_filter([
            $tx->libelula_identifier ?? null,
            $tx->reference_id ?? null,
            $tx->reference ?? null,
        ]);

        return in_array($reference, array_map('strval', $known), true);
    }

    private function redirectPage(int $txId): string
    {
        $deepLink = 'e2vapp://payment-complete?tx_id=' . $txId;

        return '<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="text-align:center; padding: 2rem; font-family: sans-serif;">
        <script>window.location.href=' . json_encode($deepLink) . ';</script>
        <h2 style="color: #0076D6;">Redireccionando a la App...</h2>
        <p>Procesando el pago, puedes cerrar esta ventana si la aplicación no se abre sola.</p>
    </body></html>';
    }
}

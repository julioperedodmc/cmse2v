<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LibelulaPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WebhookController extends Controller
{
    public function libelula(Request $request, LibelulaPaymentService $libelula)
    {
        $payload = array_merge($request->query(), $request->all());

        // Log entry IMMEDIATELY to catch any request
        \App\Models\LibelulaApiLog::create([
            'endpoint' => 'Webhook-Debug',
            'method' => $request->method(),
            'request_payload' => [
                'full_url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'headers' => $request->headers->all(),
                'payload' => $payload,
                'raw_content' => $request->getContent(),
            ],
            'response_payload' => null,
            'http_status' => 200,
        ]);

        Log::info('Libelula Webhook Received', $payload);

        try {
            $result = $this->confirmPendingRecharge($payload, $libelula);
            Log::info('Libelula webhook result', ['result' => $result]);
            return response()->json(['message' => 'Webhook processed']);
        } catch (\Throwable $e) {
            Log::error('Libelula webhook error', ['error' => $e->getMessage(), 'payload' => $payload]);
            return response()->json(['message' => 'Webhook error'], 500);
        }
    }

    /**
     * This URL is public and unauthenticated, so its payload is only used to find the
     * recharge: it is credited only when Libélula itself confirms the payment
     * (verifyStatus). It never calls handleWebhook(), whose deployed version credited
     * the recharge even when that confirmation failed.
     *
     * Libélula sends its real callbacks to callback_url (/payment-return-app, see
     * PaymentReturnController); this endpoint is kept for url_notificacion and manual tests.
     */
    private function confirmPendingRecharge(array $payload, LibelulaPaymentService $libelula): string
    {
        $tx = $this->findRecharge($payload);

        if (!$tx) {
            return 'transaction_not_found';
        }
        if (strtoupper((string) ($tx->status ?? '')) !== 'PENDING') {
            return 'already_' . strtolower((string) $tx->status);
        }
        if (!Cache::add("libelula:callback:{$tx->id}", true, 30)) {
            return 'recently_checked';
        }

        return $libelula->verifyStatus((int) $tx->id) ? 'confirmed' : 'not_confirmed';
    }

    private function findRecharge(array $payload): ?object
    {
        $references = array_values(array_filter([
            $payload['identificador'] ?? null,
            $payload['transaction_id'] ?? null,
        ], fn ($value) => is_scalar($value) && (string) $value !== ''));

        $query = DB::table('wallet_transactions')->where('payment_method', 'like', 'LIBELULA%');

        if ($references) {
            $references = array_map('strval', $references);
            $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';
            $hasIdentifier = Schema::hasColumn('wallet_transactions', 'libelula_identifier');

            return $query->where(function ($q) use ($references, $refCol, $hasIdentifier) {
                $q->whereIn($refCol, $references);
                if ($hasIdentifier) {
                    $q->orWhereIn('libelula_identifier', $references);
                }
            })->first();
        }

        // Without a reference, tx_id alone is accepted: verifyStatus still has to confirm it.
        $txId = (int) ($payload['tx_id'] ?? 0);

        return $txId > 0 ? $query->where('id', $txId)->first() : null;
    }
}

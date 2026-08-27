<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LibelulaPaymentService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $settings = \App\Models\SystemSetting::get();
        $this->baseUrl = rtrim($settings->libelula_api_url ?: env('LIBELULA_API_URL', 'https://api.libelula.bo/rest'), '/');
        $this->apiKey = (string) ($settings->libelula_app_key ?: env('LIBELULA_APP_KEY', 'a744e805-62f9-37bb-f3f6-beb3877079b8'));
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function createPayment(Wallet $wallet, float $amount, string $description = 'Recarga Wallet', array $invoiceData = [], bool $isPaid = false, float $discount = 0): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'LIBELULA_APP_KEY no configurada',
            ];
        }

        $amount = round($amount, 2);
        $user = $wallet->user;

        $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';
        $statusCol = Schema::hasColumn('wallet_transactions', 'status') ? 'status' : null;
        $currencyCol = Schema::hasColumn('wallet_transactions', 'currency');
        $balanceAfterCol = Schema::hasColumn('wallet_transactions', 'balance_after');

        $txId = $invoiceData['transaction_id'] ?? null;
        $localReference = $invoiceData['identificador'] ?? null;

        if (!($invoiceData['internal_usage_tx'] ?? false)) {
            $txId = DB::transaction(function () use ($wallet, $user, $amount, $description, $refCol, $statusCol, $currencyCol, $balanceAfterCol) {
                $insert = [
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'type' => 'RECHARGE',
                    'amount' => $amount,
                    $refCol => 'LIBELULA-PENDING-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                    'description' => $description,
                    'payment_method' => 'LIBELULA',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($currencyCol) {
                    $insert['currency'] = $wallet->currency ?? 'BOB';
                }
                if ($balanceAfterCol) {
                    $insert['balance_after'] = (float) $wallet->balance;
                }
                if ($statusCol) {
                    $insert[$statusCol] = 'PENDING';
                }

                return DB::table('wallet_transactions')->insertGetId($insert);
            });

            $localReference = 'LBE-' . $txId . '-' . now()->format('YmdHis');
            DB::table('wallet_transactions')->where('id', $txId)->update([$refCol => $localReference]);
        } else {
            if (!$localReference) {
                $localReference = 'INV-' . ($invoiceData['session_id'] ?? now()->timestamp) . '-' . random_int(100, 999);
            }
        }

        $invoicingPolicy = \App\Models\SystemSetting::get()->invoicing_policy ?: 'recharge';
        $canInvoice = ($invoicingPolicy === 'recharge' || ($invoiceData['emite_factura'] ?? false) === true);

        $returnUrl = $invoiceData['return_url'] ?? url('/payment-return-app');
        if (str_contains($returnUrl, '?')) {
            $returnUrl .= "&tx_id={$txId}";
        } else {
            $returnUrl .= "?tx_id={$txId}";
        }

        $isCredit = $invoiceData['is_credit'] ?? false;
        $settings = \App\Models\SystemSetting::get();
        $apiKey = (($isPaid || $isCredit) && $settings->libelula_invoicing_app_key) 
                    ? $settings->libelula_invoicing_app_key 
                    : $this->apiKey;

        $plateNumber = $invoiceData['vehicle_plate'] 
            ?? $invoiceData['placa'] 
            ?? $invoiceData['placa_vehiculo'] 
            ?? $user?->vehicles()?->latest()?->first()?->plate 
            ?? '1111ABC';

        $payload = [
            'appkey' => $apiKey,
            'email_cliente' => $user->email,
            'nombre_cliente' => $user->name,
            'apellido_cliente' => '',
            'ci' => ($invoiceData['documento'] ?? $user->billing_document) ?: '',
            'razon_social' => ($invoiceData['razon_social'] ?? $user->billing_razon_social) ?: $user->name,
            'numero_documento' => ($invoiceData['documento'] ?? $user->billing_document) ?: '',
            'codigo_tipo_documento' => ($invoiceData['codigo_tipo_documento'] ?? 'CI'),
            'complemento_documento' => ($invoiceData['complemento'] ?? '') ?: '',
            'identificador' => $localReference,
            'url_notificacion' => route('api.webhooks.libelula'),
            'callback_url' => $returnUrl,
            'descripcion' => $description,
            'moneda' => $wallet->currency ?? 'BOB',
            'monto' => number_format((float)$amount, 2, '.', ''),
            'descuento_global' => number_format((float)$discount, 2, '.', ''),
            'codigo_documento_sector' => $settings->libelula_sector_code ?? '31',
            'emite_factura' => $canInvoice,
            'lineas_metadatos' => [
                [
                    'nombre' => 'placa Vehiculo',
                    'dato' => (string) $plateNumber,
                ]
            ],
        ];

        // Handle dynamic line items or default to single line
        if (!empty($invoiceData['line_items'])) {
            $payload['lineas_detalle_deuda'] = array_map(function($item) {
                return [
                    'concepto' => (string) ($item['concepto'] ?? ''),
                    'cantidad' => (int) ($item['cantidad'] ?? 1),
                    'costo_unitario' => number_format((float)($item['costo_unitario'] ?? 0), 2, '.', ''),
                    'descuento_unitario' => number_format((float)($item['descuento_unitario'] ?? 0), 2, '.', ''),
                    'detalle' => (string) ($item['detalle'] ?? ''),
                    'codigo_producto' => (string) ($item['codigo_producto'] ?? '1'),
                ];
            }, $invoiceData['line_items']);
        } else {
            $payload['lineas_detalle_deuda'] = [
                [
                    'concepto' => $description,
                    'cantidad' => (int) 1,
                    'costo_unitario' => $amount,
                    'descuento_unitario' => $discount,
                    'detalle' => $description,
                    'codigo_producto' => $this->resolveProductCode('RECHARGE'),
                ],
            ];
        }

        if ($isPaid || $isCredit) {
            $payload['pago_realizado'] = true;
            $canalCaja = $settings->libelula_canal_caja ?: env('LIBELULA_CANAL_CAJA', '23955c77e357e4c5da69917858462130b124019b9c9f3c3b6a70b55b6e4464cd');
            if ($canalCaja) {
                $payload['canal_caja'] = $canalCaja;
                $payload['canal_caja_sucursal'] = $settings->libelula_canal_caja_sucursal ?: 'SUCURSAL 1';
                $payload['canal_caja_usuario'] = $settings->libelula_canal_caja_usuario ?: 'CAJERO 1';
                $payload['pago_confirmado'] = true;
            } else {
                $payload['pago_confirmado'] = true;
                $payload['metodo_pago'] = 'MANUAL';
                $payload['estado'] = 'PAGADO';
            }
        }

        unset($payload['documento']);
        unset($payload['complemento']);

        try {
            $logHeader = "Libelula: [TX: {$txId}] CLIENT: {$user->name} ({$user->email}) - AMOUNT: {$payload['monto']} - DISC: {$payload['descuento_global']}";
            Log::info("{$logHeader} - Registering debt...", ['url' => "{$this->baseUrl}/deuda/registrar", 'payload' => $payload]);
            
            $resp = Http::timeout(20)->post("{$this->baseUrl}/deuda/registrar", $payload);
            $data = $resp->json() ?: [];
            
            Log::info("{$logHeader} - Response Status: {$resp->status()}", ['data' => $data]);

            \App\Models\LibelulaApiLog::create([
                'endpoint' => '/deuda/registrar',
                'method' => 'POST',
                'request_payload' => $payload,
                'response_payload' => $data,
                'http_status' => $resp->status(),
                'transaction_id' => $txId,
            ]);

            $errorCode = (int) ($data['error'] ?? 1);
            $isAlreadyExists = ($errorCode === 2);

            if (!$resp->successful() || ($errorCode !== 0 && !$isAlreadyExists)) {
                $msg = $data['mensaje'] ?? ('HTTP ' . $resp->status());
                $this->markFailed($txId, ['error' => $msg, 'response' => $data]);

                return [
                    'success' => false,
                    'message' => 'Libélula rechazó la creación de pago',
                    'detail' => $msg,
                ];
            }

            $invoiceUrl = $data['factura_electronica_url'] ?? $data['url_pasarela_pagos'] ?? null;
            if (empty($invoiceUrl) && !empty($data['facturas_electronicas']) && is_array($data['facturas_electronicas'])) {
                $invoiceUrl = $data['facturas_electronicas'][0]['url'] ?? null;
            }

            if ($txId) {
                $tx = WalletTransaction::find($txId);
                if ($tx) {
                    $update = ['updated_at' => now()];
                    if (Schema::hasColumn('wallet_transactions', 'external_payment_id')) {
                        $update['external_payment_id'] = $data['id'] ?? $data['id_transaccion'] ?? null;
                    }
                    if (Schema::hasColumn('wallet_transactions', 'payment_url')) {
                        $update['payment_url'] = $data['url_pasarela_pagos'] ?? null;
                    }
                    if (Schema::hasColumn('wallet_transactions', 'invoice_url')) {
                        $electronicInvoices = $data['facturas_electronicas'] ?? $data['data']['facturas_electronicas'] ?? [];
                        $invoiceUrl = !empty($electronicInvoices) ? ($electronicInvoices[0]['url'] ?? null) : null;
                        if (!$invoiceUrl && !empty($electronicInvoices) && !empty($electronicInvoices[0]['identificador'])) {
                            $invoiceUrl = 'https://pagos.libelula.bo/factura/' . $electronicInvoices[0]['identificador'];
                        }

                        $update['invoice_url'] = $invoiceUrl 
                            ?? $data['url_factura'] 
                            ?? $data['pdf_factura'] 
                            ?? $data['pdf'] 
                            ?? $data['url_sin'] 
                            ?? $data['url_cliente']
                            ?? $data['pdf_url']
                            ?? null;
                    }
                    $tx->update($update);
                }
            }

            if ($resp->successful() && ($errorCode === 0 || $isAlreadyExists)) {
                $paymentUrl = $data['url_pasarela_pagos'] ?? null;
                
                if ($txId) {
                    DB::table('wallet_transactions')
                        ->where('id', $txId)
                        ->update(['payment_url' => $paymentUrl]);
                }

                if ($isPaid && $txId) {
                    $this->markCompleted($txId, [
                        'payment_method' => 'MANUAL_CMS',
                        'id_transaccion' => $data['id'] ?? $data['id_transaccion'] ?? 'MANUAL',
                        'estado' => 'PAGADO'
                    ]);
                }

                $invoiceUrl = null;
                if (!empty($data['facturas_electronicas'])) {
                    $invoiceUrl = $data['facturas_electronicas'][0]['url'] ?? null;
                }
                if (!$invoiceUrl && !empty($data['data']['facturas_electronicas'])) {
                    $invoiceUrl = $data['data']['facturas_electronicas'][0]['url'] ?? null;
                }

                return [
                    'success' => true,
                    'id' => $data['id'] ?? $data['id_transaccion'] ?? null,
                    'transaction_id' => $data['id'] ?? $data['id_transaccion'] ?? null,
                    'payment_url' => $paymentUrl,
                    'invoice_url' => $invoiceUrl,
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => 'Error inesperado al procesar respuesta de Libélula',
            ];
        } catch (\Throwable $e) {
            if ($txId) {
                $this->markFailed($txId, ['exception' => $e->getMessage()]);
            }
            Log::error('Libelula createPayment exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Error de conexión con Libélula',
                'detail' => $e->getMessage(),
            ];
        }
    }

    public function verifyStatus(int $txId): bool
    {
        $tx = DB::table('wallet_transactions')->where('id', $txId)->first();
        if (!$tx) return false;

        $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';
        $localReference = $tx->{$refCol};

        try {
            Log::info("Libelula: Verifying status for TX {$txId}", ['ref' => $localReference]);
            
            $resp = Http::timeout(10)->asForm()->post("{$this->baseUrl}/deuda/consultar", [
                'appkey' => $this->apiKey,
                'id' => $localReference
            ]);

            $rawBody = $resp->body();
            $data = $resp->json() ?: [];
            Log::info("Libelula: Verification response RAW", ['status' => $resp->status(), 'body' => $rawBody]);

            if ($resp->successful() && (int)($data['error'] ?? 1) === 0) {
                $item = $data['datos'][0] ?? [];
                $isPaid = (int)($item['pagado'] ?? 0) === 1;
                
                if ($isPaid) {
                    $payloadToPass = array_merge($item, [
                        'facturas_electronicas' => $data['facturas_electronicas'] ?? ($item['facturas_electronicas'] ?? null),
                        'data' => $data['data'] ?? ($item['data'] ?? null),
                        'factura_url' => $data['factura_url'] ?? ($item['factura_url'] ?? null),
                        'url_factura' => $data['url_factura'] ?? ($item['url_factura'] ?? null),
                        'pdf_factura' => $data['pdf_factura'] ?? ($item['pdf_factura'] ?? null),
                    ]);
                    $this->markCompleted($txId, $payloadToPass);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::error("Libelula: Verification exception", ['error' => $e->getMessage()]);
        }

        return false;
    }

    public function handleWebhook(array $data): void
    {
        $candidateIds = array_filter([
            $data['identificador'] ?? null,
            $data['transaction_id'] ?? null,
            $data['id_transaccion'] ?? null,
            $data['referencia'] ?? null,
            $data['numeroReferencia'] ?? null,
        ]);

        $tx = null;
        $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';

        foreach ($candidateIds as $cid) {
            if (empty($cid)) continue;

            $tx = DB::table('wallet_transactions')->where($refCol, (string) $cid)->first();
            if ($tx) break;

            if (is_string($cid) && str_contains($cid, '-')) {
                $tx = DB::table('wallet_transactions')->where($refCol, $cid)->first();
                if ($tx) break;

                $parts = explode('-', $cid);
                foreach ($parts as $p) {
                    if (is_numeric($p)) {
                        $tx = DB::table('wallet_transactions')->where($refCol, (string) $p)->first();
                        if ($tx) break 2;
                        
                        $tx = DB::table('wallet_transactions')->where('id', (int) $p)->first();
                        if ($tx) break 2;
                    }
                }
            }

            if (is_numeric($cid)) {
                $tx = DB::table('wallet_transactions')->where('id', (int) $cid)->first();
                if ($tx) break;
            }
        }

        if (!$tx) {
            Log::warning('Libelula webhook transaction not found', ['payload' => $data]);
            return;
        }

        $status = strtoupper((string) ($data['status'] ?? $data['estado'] ?? ''));
        $paid = in_array($status, ['PAID', 'PAGADO', 'COMPLETED', 'SUCCESS'], true) || (int) ($data['error'] ?? 0) === 0;

        if ($paid) {
            $verified = $this->verifyStatus((int) $tx->id);
            if (!$verified) {
                $this->markCompleted((int) $tx->id, $data);
            }
        } else {
            $this->markFailed((int) $tx->id, $data);
        }
    }

    private function markCompleted(int $txId, array $payload): void
    {
        DB::transaction(function () use ($txId, $payload) {
            $tx = DB::table('wallet_transactions')->where('id', $txId)->lockForUpdate()->first();
            if (!$tx) {
                return;
            }

            if ($tx->payment_method === 'CREDITO') {
                $invoiceUrlCol = Schema::hasColumn('wallet_transactions', 'invoice_url');
                $invoiceNumberCol = Schema::hasColumn('wallet_transactions', 'invoice_number');
                
                $updateInvoice = [];
                
                if ($invoiceNumberCol && empty($tx->invoice_number)) {
                    $updateInvoice['invoice_number'] = $payload['invoice_number']
                        ?? $payload['nro_factura']
                        ?? $payload['numero_factura']
                        ?? null;
                }
                
                if ($invoiceUrlCol && empty($tx->invoice_url)) {
                    $electronicInvoices = $payload['facturas_electronicas'] ?? $payload['data']['facturas_electronicas'] ?? [];
                    $invoiceUrl = !empty($electronicInvoices) ? ($electronicInvoices[0]['url'] ?? null) : null;
                    
                    if (!$invoiceUrl && !empty($electronicInvoices) && !empty($electronicInvoices[0]['identificador'])) {
                        $invoiceUrl = 'https://pagos.libelula.bo/factura/' . $electronicInvoices[0]['identificador'];
                    }

                    $updateInvoice['invoice_url'] = $invoiceUrl 
                        ?? $payload['invoice_url']
                        ?? $payload['factura_url']
                        ?? $payload['url_factura']
                        ?? $payload['url_sin']
                        ?? $payload['url_cliente']
                        ?? $payload['pdf_url']
                        ?? $payload['pdf_factura']
                        ?? $payload['url_factura_electronica']
                        ?? null;
                }
                
                if (!empty($updateInvoice)) {
                    $filteredUpdate = array_filter($updateInvoice, function($val) {
                        return !is_null($val) && $val !== '';
                    });
                    
                    if (!empty($filteredUpdate)) {
                        $filteredUpdate['updated_at'] = now();
                        DB::table('wallet_transactions')->where('id', $txId)->update($filteredUpdate);
                        Log::info("Libelula markCompleted: Updated invoice info for CREDITO transaction #{$txId}", $filteredUpdate);
                    }
                }
                return;
            }

            $statusCol = Schema::hasColumn('wallet_transactions', 'status') ? 'status' : null;
            if ($statusCol && strtoupper((string) $tx->{$statusCol}) === 'COMPLETED') {
                $invoiceUrlCol = Schema::hasColumn('wallet_transactions', 'invoice_url');
                $invoiceNumberCol = Schema::hasColumn('wallet_transactions', 'invoice_number');
                
                $updateInvoice = [];
                
                if ($invoiceNumberCol && empty($tx->invoice_number)) {
                    $updateInvoice['invoice_number'] = $payload['invoice_number']
                        ?? $payload['nro_factura']
                        ?? $payload['numero_factura']
                        ?? null;
                }
                
                if ($invoiceUrlCol && empty($tx->invoice_url)) {
                    $electronicInvoices = $payload['facturas_electronicas'] ?? $payload['data']['facturas_electronicas'] ?? [];
                    $invoiceUrl = !empty($electronicInvoices) ? ($electronicInvoices[0]['url'] ?? null) : null;
                    
                    if (!$invoiceUrl && !empty($electronicInvoices) && !empty($electronicInvoices[0]['identificador'])) {
                        $invoiceUrl = 'https://pagos.libelula.bo/factura/' . $electronicInvoices[0]['identificador'];
                    }

                    $updateInvoice['invoice_url'] = $invoiceUrl 
                        ?? $payload['invoice_url']
                        ?? $payload['factura_url']
                        ?? $payload['url_factura']
                        ?? $payload['url_sin']
                        ?? $payload['url_cliente']
                        ?? $payload['pdf_url']
                        ?? $payload['pdf_factura']
                        ?? $payload['url_factura_electronica']
                        ?? null;
                }
                
                if (!empty($updateInvoice)) {
                    $filteredUpdate = array_filter($updateInvoice, function($val) {
                        return !is_null($val) && $val !== '';
                    });
                    
                    if (!empty($filteredUpdate)) {
                        $filteredUpdate['updated_at'] = now();
                        DB::table('wallet_transactions')->where('id', $txId)->update($filteredUpdate);
                        Log::info("Libelula markCompleted: Updated invoice info for already COMPLETED transaction #{$txId}", $filteredUpdate);
                    }
                }
                return;
            }

            $wallet = DB::table('wallets')->where('id', $tx->wallet_id)->lockForUpdate()->first();
            if (!$wallet) {
                return;
            }

            $metadata = json_decode($tx->metadata ?? '{}', true);
            $skipUpdate = $metadata['skip_wallet_update'] ?? false;

            $newBalance = (float) $wallet->balance;
            if (!$skipUpdate) {
                $newBalance = round(((float) $wallet->balance) + ((float) $tx->amount), 2);
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $newBalance,
                    'updated_at' => now(),
                ]);
            }

            $method = $payload['payment_method']
                ?? $payload['metodo_pago'] 
                ?? $payload['medio_pago'] 
                ?? $payload['tipo_pago'] 
                ?? $payload['glosa_metodo_pago'] 
                ?? $payload['glosa_pago']
                ?? 'LIBELULA';

            $update = [
                'payment_method' => 'LIBELULA/' . strtoupper((string) $method),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('wallet_transactions', 'balance_after')) {
                $update['balance_after'] = $newBalance;
            }
            if ($statusCol) {
                $update[$statusCol] = 'COMPLETED';
            }
            if (Schema::hasColumn('wallet_transactions', 'external_payment_id')) {
                $update['external_payment_id'] = $payload['id_transaccion'] ?? ($payload['transaction_id'] ?? null);
            }
            if (Schema::hasColumn('wallet_transactions', 'invoice_number')) {
                $update['invoice_number'] = $payload['invoice_number']
                    ?? $payload['nro_factura']
                    ?? $payload['numero_factura']
                    ?? null;
            }
            if (Schema::hasColumn('wallet_transactions', 'invoice_url')) {
                $electronicInvoices = $payload['facturas_electronicas'] ?? $payload['data']['facturas_electronicas'] ?? [];
                $invoiceUrl = !empty($electronicInvoices) ? ($electronicInvoices[0]['url'] ?? null) : null;
                
                if (!$invoiceUrl && !empty($electronicInvoices) && !empty($electronicInvoices[0]['identificador'])) {
                    $invoiceUrl = 'https://pagos.libelula.bo/factura/' . $electronicInvoices[0]['identificador'];
                }

                $update['invoice_url'] = $invoiceUrl 
                    ?? $payload['invoice_url']
                    ?? $payload['factura_url']
                    ?? $payload['url_factura']
                    ?? $payload['url_sin']
                    ?? $payload['url_cliente']
                    ?? $payload['pdf_url']
                    ?? $payload['pdf_factura']
                    ?? $payload['url_factura_electronica']
                    ?? $tx->invoice_url;
            }
            if (Schema::hasColumn('wallet_transactions', 'metadata')) {
                $update['metadata'] = json_encode(['webhook' => $payload]);
            }

            DB::table('wallet_transactions')->where('id', $txId)->update($update);

            try {
                $user = \App\Models\User::find($wallet->user_id);
                if ($user) {
                    $user->notify(new \App\Notifications\GeneralNotification(
                        'Recarga exitosa',
                        "Tu recarga de Bs " . number_format($tx->amount, 2) . " ha sido procesada.",
                        ['type' => 'RECHARGE', 'amount' => $tx->amount, 'transaction_id' => $txId]
                    ));
                }
            } catch (\Throwable $e) {
                Log::error('Notification error in Libelula markCompleted', ['error' => $e->getMessage()]);
            }
        });
    }

    public function createManualInvoice(WalletTransaction $tx, float $amount, string $description, ?array $lineItems = null, float $discount = 0): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Libélula no configurada'];
        }

        $settings = \App\Models\SystemSetting::get();
        $user = $tx->user;
        $amount = round($amount, 2);

        $plate = $tx->metadata['vehicle_plate'] 
            ?? $tx->metadata['placa'] 
            ?? $user?->vehicles()?->latest()?->first()?->plate 
            ?? '1111ABC';

        $payload = [
            'appkey' => $this->apiKey,
            'identificador' => "MAN-{$tx->id}-" . now()->timestamp,
            'emite_factura' => 1,
            'email_cliente' => $user->email,
            'nombre_cliente' => $user->name,
            'apellido_cliente' => '',
            'razon_social' => $tx->metadata['razon_social'] ?? $user->billing_razon_social ?? $user->name,
            'numero_documento' => $tx->metadata['documento'] ?? $user->billing_document ?? '0',
            'codigo_tipo_documento' => $this->resolveDocType($tx->metadata['documento'] ?? $user->billing_document ?? '0'),
            'complemento_documento' => '',
            'descuento_global' => (string) round($discount, 2),
            
            'canal_caja' => $settings->libelula_canal_caja ?: env('LIBELULA_CANAL_CAJA', '23955c77e357e4c5da69917858462130b124019b9c9f3c3b6a70b55b6e4464cd'),
            'canal_caja_sucursal' => $settings->libelula_canal_caja_sucursal ?: 'SUCURSAL 1',
            'canal_caja_usuario' => $settings->libelula_canal_caja_usuario ?: 'CAJERO 1',
            'descripcion' => "Factura emitida manualmente",
            'codigo_documento_sector' => $settings->libelula_sector_code ?? '31',
            'lineas_metadatos' => [
                [
                    'nombre' => 'placa Vehiculo',
                    'dato' => (string) $plate,
                ]
            ],
            'pago_realizado' => true,
            'monto' => (string) round($amount, 2),

            'lineas_detalle_deuda' => !empty($lineItems) ? array_map(function($item) {
                return [
                    'concepto' => (string) ($item['concepto'] ?? ''),
                    'cantidad' => (int) ($item['cantidad'] ?? 1),
                    'costo_unitario' => (float) ($item['costo_unitario'] ?? 0),
                    'descuento_unitario' => (float) ($item['descuento_unitario'] ?? 0),
                    'detalle' => (string) ($item['detalle'] ?? ''),
                    'codigo_producto' => (string) ($item['codigo_producto'] ?? '1'),
                ];
            }, $lineItems) : [
                [
                    'concepto' => $description,
                    'cantidad' => (int) 1,
                    'costo_unitario' => (float) $amount,
                    'codigo_producto' => $this->resolveProductCode('RECHARGE'),
                    'descuento_unitario' => 0,
                    'ignora_factura' => false
                ]
            ]
        ];

        try {
            $logHeader = "Libelula: [TX: {$tx->id}] CLIENT: {$user->name} - AMOUNT: {$payload['monto']} - Manual Invoice";
            Log::info("{$logHeader} Request", ['payload' => $payload]);
            
            $resp = Http::timeout(20)->post("{$this->baseUrl}/deuda/registrar", $payload);
            $data = $resp->json() ?: [];
            
            Log::info("{$logHeader} Response - Status: {$resp->status()}", ['data' => $data]);

            \App\Models\LibelulaApiLog::create([
                'endpoint' => '/deuda/registrar (Manual)',
                'method' => 'POST',
                'request_payload' => $payload,
                'response_payload' => $data,
                'http_status' => $resp->status(),
                'transaction_id' => $tx->id,
            ]);

            if ($resp->successful() && (int) ($data['error'] ?? 1) === 0) {
                $this->markCompleted($tx->id, array_merge($data, ['payment_method' => 'MANUAL_CASH']));
                
                $electronicInvoices = $data['data']['facturas_electronicas'] ?? [];
                $invoiceUrl = !empty($electronicInvoices) ? ($electronicInvoices[0]['url'] ?? null) : null;
                if (!$invoiceUrl && !empty($electronicInvoices) && !empty($electronicInvoices[0]['identificador'])) {
                    $invoiceUrl = 'https://pagos.libelula.bo/factura/' . $electronicInvoices[0]['identificador'];
                }

                return [
                    'success' => true,
                    'invoice_url' => $invoiceUrl 
                                        ?? $data['url_factura'] 
                                        ?? $data['pdf_factura'] 
                                        ?? $data['pdf'] 
                                        ?? $data['url_sin']
                                        ?? $data['url_cliente']
                                        ?? $data['pdf_url']
                                        ?? null,
                    'message' => 'Factura emitida correctamente'
                ];
            }

            return [
                'success' => ($data['status'] ?? 0) === 200 && empty($data['data']['error']),
                'message' => $data['data']['mensaje'] ?? 'Error desconocido',
                'detail' => $data['data']['detalle'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('Libelula Manual Invoice Exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()];
        }
    }

    public function testInvoiceRequest(array $payloadData): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Libélula API Key no configurada'];
        }

        $settings = \App\Models\SystemSetting::get();
        
        $payload = [
            'appkey' => $this->apiKey,
            'identificador' => "TEST-" . now()->timestamp,
            'emite_factura' => 1,
            'email_cliente' => $payloadData['email_cliente'] ?? 'test@example.com',
            'nombre_cliente' => $payloadData['nombre_cliente'] ?? 'Test User',
            'apellido_cliente' => '',
            'razon_social' => $payloadData['razon_social'] ?? 'Test User',
            'numero_documento' => $payloadData['numero_documento'] ?? '1234567',
            'codigo_tipo_documento' => $this->resolveDocType($payloadData['numero_documento'] ?? '1234567'),
            'complemento_documento' => '',
            'descuento_global' => (string) round((float) ($payloadData['descuento_global'] ?? 0), 2),
            
            'canal_caja' => $settings->libelula_canal_caja ?: env('LIBELULA_CANAL_CAJA', '23955c77e357e4c5da69917858462130b124019b9c9f3c3b6a70b55b6e4464cd'),
            'canal_caja_sucursal' => $settings->libelula_canal_caja_sucursal ?: 'SUCURSAL 1',
            'canal_caja_usuario' => $settings->libelula_canal_caja_usuario ?: 'CAJERO 1',
            'descripcion' => $payloadData['concepto'] ?? "Prueba desde Debugger",
            'codigo_documento_sector' => $settings->libelula_sector_code ?? '31',
            'lineas_metadatos' => [
                [
                    'nombre' => 'placa Vehiculo',
                    'dato' => (string) ($payloadData['placa_vehiculo'] ?? '1111ABC'),
                ]
            ],
            'pago_realizado' => true,

            'lineas_detalle_deuda' => [
                [
                    'concepto' => $payloadData['concepto'] ?? 'Ítem de prueba',
                    'cantidad' => (int) 1,
                    'costo_unitario' => round((float) ($payloadData['monto'] ?? 0), 2),
                    'descuento_unitario' => round((float) ($payloadData['descuento'] ?? 0), 2),
                    'detalle' => 'Detalle prueba',
                    'codigo_producto' => $payloadData['codigo_producto'] ?? '1',
                    'ignora_factura' => false
                ]
            ]
        ];

        Log::info('Libelula Debug Request', ['payload' => $payload]);

        try {
            $response = Http::timeout(30)->post($this->baseUrl . '/deuda/registrar', $payload);
            
            Log::info('Libelula Debug Response', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            \App\Models\LibelulaApiLog::create([
                'endpoint' => '/deuda/registrar (Debugger)',
                'method' => 'POST',
                'request_payload' => $payload,
                'response_payload' => $response->json(),
                'http_status' => $response->status(),
                'transaction_id' => null,
            ]);

            return [
                'http_status' => $response->status(),
                'response_body' => $response->json(),
                'request_payload' => $payload
            ];
        } catch (\Exception $e) {
            return [
                'http_status' => 500,
                'response_body' => ['error' => 'Exception: ' . $e->getMessage()],
                'request_payload' => $payload
            ];
        }
    }

    private function resolveProductCode(?string $internalCode = 'RECHARGE'): string
    {
        $settings = \App\Models\SystemSetting::get();
        
        try {
            $mappedProductId = null;
            if ($internalCode === 'RECHARGE') {
                $mappedProductId = $settings->product_recharge_id;
            } elseif ($internalCode === 'ENERGY-SVC') {
                $mappedProductId = $settings->product_energy_id;
            } elseif ($internalCode === 'CONN-FEE') {
                $mappedProductId = $settings->product_connection_id;
            } elseif ($internalCode === 'TIME-PENALTY') {
                $mappedProductId = $settings->product_penalty_id;
            }

            if ($mappedProductId) {
                $product = \App\Models\Product::find($mappedProductId);
                if ($product && $product->siat_product_code) {
                    return $product->siat_product_code;
                }
            }

            if (Schema::hasTable('products')) {
                $product = \App\Models\Product::where('internal_code', $internalCode)
                    ->orWhere('name', 'LIKE', "%{$internalCode}%")
                    ->first();
                
                if ($product && $product->siat_product_code) {
                    return $product->siat_product_code;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Error resolving product code for {$internalCode}: " . $e->getMessage());
        }

        return $settings->libelula_product_code ?: '1';
    }

    private function resolveDocType(?string $doc): string
    {
        if (!$doc) return 'CI';
        $doc = preg_replace('/[^0-9]/', '', $doc);
        return (strlen($doc) > 9) ? 'NIT' : 'CI';
    }

    private function markFailed(int $txId, array $payload): void
    {
        $update = ['updated_at' => now()];

        if (Schema::hasColumn('wallet_transactions', 'status')) {
            $update['status'] = 'FAILED';
        }
        if (Schema::hasColumn('wallet_transactions', 'metadata')) {
            $update['metadata'] = json_encode(['error' => $payload]);
        }

        DB::table('wallet_transactions')->where('id', $txId)->update($update);
    }
}

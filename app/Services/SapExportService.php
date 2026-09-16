<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\ChargingSession;
use Illuminate\Support\Carbon;

class SapExportService
{
    public function getCustomers(bool $onlyUnsynced = false, ?int $limit = null)
    {
        $query = User::query()->latest();
        if ($onlyUnsynced) {
            $query->whereNull('sap_synced_at');
        }
        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()->map(function ($user) {
            return [
                'sap_client_code' => $user->billing_document ?: 'not registered',
                'name' => $user->name,
                'email' => $user->email,
                'razon_social' => $user->billing_razon_social,
                'nit_ci' => $user->billing_document,
                'doc_type' => $user->billing_doc_type,
                'company_id' => $user->company_id,
                'company_name' => $user->company?->name,
                'is_admin' => $user->is_admin,
                'balance' => $user->wallet?->balance ?? 0,
                'synced_at' => $user->sap_synced_at,
            ];
        });
    }

    public function getPayments(bool $onlyUnsynced = false, ?int $limit = null)
    {
        $query = WalletTransaction::with(['user', 'wallet.user'])->latest();
        if ($onlyUnsynced) {
            $query->whereNull('sap_synced_at');
        }
        if ($limit) {
            $query->limit($limit);
        }

        $policy = \App\Models\SystemSetting::get()->invoicing_policy ?? 'recharge';

        return $query->whereIn('type', ['RECHARGE', 'CHARGE', 'REFUND', 'ADJUSTMENT'])
            ->where('status', 'COMPLETED')
            ->get()
            ->map(function ($tx) use ($policy) {
                $user = $tx->user ?? ($tx->wallet->user ?? null);
                
                // Determine label for SAP
                $label = $tx->type;
                if ($tx->type === 'RECHARGE') {
                    $label = ($policy === 'recharge') ? 'RECARGA_OFICIAL' : 'ANTICIPO_CLIENTE';
                }

                $meta = $tx->metadata ?? [];
                $lineItems = [];
                if (is_array($meta) && isset($meta['line_items'])) {
                    $lineItems = $meta['line_items'];
                } else {
                    $lineItems = [
                        [
                            'concepto' => $tx->description ?: ($tx->type === 'RECHARGE' ? 'Recarga de saldo' : 'Consumo Billetera'),
                            'cantidad' => 1,
                            'costo_unitario' => (float) $tx->amount,
                            'descuento_unitario' => 0.0,
                            'detalle' => $tx->description ?: ($tx->type === 'RECHARGE' ? 'Recarga Wallet' : 'Consumo Carga'),
                            'codigo_producto' => $tx->type === 'RECHARGE' ? 'RECHARGE' : 'ENERGY-SVC',
                        ]
                    ];
                }

                $session = null;
                if ($tx->type === 'CHARGE' && !empty($tx->reference_id)) {
                    $session = ChargingSession::where('transaction_id', $tx->reference_id)->first();
                }

                return [
                    'id' => $tx->id,
                    'sap_client_code' => $user?->billing_document ?: 'not registered',
                    'customer_name' => $user?->name ?? 'Unknown',
                    'nit' => $user?->billing_document,
                    'razon_social' => $user?->billing_razon_social,
                    'date' => $tx->created_at->copy()->setTimezone('America/La_Paz')->toIso8601String(),
                    'type' => $tx->type,
                    'transaction_type_label' => $label,
                    'amount' => (float) $tx->amount,
                    'currency' => $tx->currency,
                    'balance_after' => (float) $tx->balance_after,
                    'description' => $tx->description,
                    'payment_method' => $tx->payment_method ?? 'WALLET',
                    'reference_id' => $tx->reference_id,
                    'external_id_pos' => $tx->external_payment_id,
                    'bank_receipt' => $tx->bank_receipt_number,
                    'pos_correlative' => $tx->pos_correlative,
                    'internal_ref' => $tx->id,
                    'rfid_tag' => $tx->metadata['rfid_tag'] ?? ($session?->rfid_tag_id ?? null),
                    'charging_session_id' => $session?->id,
                    'steve_transaction_id' => $tx->type === 'CHARGE' ? $tx->reference_id : null,
                    'global_discount' => (float) ($tx->metadata['global_discount'] ?? 0),
                    'invoice_number' => $tx->invoice_number,
                    'invoice_url' => $tx->invoice_url,
                    'status' => $tx->status,
                    'item_code' => $tx->type === 'RECHARGE' ? 'RECARGA_CREDITO' : 'CONSUMO_BILLETERA',
                    'item_description' => $tx->type === 'RECHARGE' ? 'Recarga de crédito para billetera virtual' : 'Consumo de energía desde billetera virtual',
                    'transaction_lines' => $lineItems,
                    'user_id' => $user?->id,
                    'user_email' => $user?->email,
                ];
            });
    }

    public function getSessions(bool $onlyUnsynced = false, ?int $limit = null)
    {
        $query = ChargingSession::with(['user', 'station'])->latest();
        if ($onlyUnsynced) {
            $query->whereNull('sap_synced_at');
        }
        if ($limit) {
            $query->limit($limit);
        }

        $policy = \App\Models\SystemSetting::get()->invoicing_policy ?? 'recharge';

        return $query->where('status', 'COMPLETED')
            ->get()
            ->map(function ($session) use ($policy) {
                $startTime = $session->start_time;
                if ($startTime && !($startTime instanceof Carbon)) {
                    $startTime = Carbon::parse($startTime);
                }

                $stopTime = $session->stop_time;
                if ($stopTime && !($stopTime instanceof Carbon)) {
                    $stopTime = Carbon::parse($stopTime);
                }

                 // Label for SAP based on policy
                 $label = ($policy === 'usage') ? 'FACTURA_ENERGIA' : 'CONSUMO_INTERNO';
 
                 $walletTx = null;
                 if (!empty($session->transaction_id)) {
                     $walletTx = WalletTransaction::where('type', 'CHARGE')
                         ->where('reference_id', (string) $session->transaction_id)
                         ->first();
                 }

                 return [
                     'sap_client_code' => $session->user?->billing_document ?? 'not registered',
                     'customer_name' => $session->user?->name ?? 'Anónimo (GDPR)',
                     'nit' => $session->user?->billing_document ?? null,
                     'razon_social' => $session->user?->billing_razon_social ?? null,
                     'station' => $session->station->name ?? 'Unknown',
                     'start_time' => $startTime?->copy()->setTimezone('America/La_Paz')->toIso8601String(),
                     'end_time' => $stopTime?->copy()->setTimezone('America/La_Paz')->toIso8601String(),
                     'energy_kwh' => (float) $session->total_energy_kwh,
                     'item_code' => $session->item_code ?? 'EV_CHARGE',
                     'item_description' => $session->item_description ?? 'Suministro de energía eléctrica para vehículo',
                     'price_unit' => $session->rate_kwh,
                     'total_amount' => (float) $session->total_cost,
                     'currency' => $session->currency,
                     'internal_ref' => $session->id,
                     'transaction_type_label' => $label,
                     'rfid_tag' => $session->rfid_tag_id,
                     'rfid_tag_id' => $session->rfid_tag_id,
                     'steve_transaction_id' => $session->transaction_id,
                     'wallet_transaction_id' => $walletTx?->id,
                     'invoice_url' => $session->invoice_url,
                     'status' => $session->status,
                     'stop_reason' => $session->stop_reason,
                     'start_soc' => $session->start_soc,
                     'stop_soc' => $session->stop_soc,
                     'station_id' => $session->station_id,
                     'connector_id' => $session->connector_id,
                     'user_id' => $session->user_id,
                     'product_id' => $session->product_id,
                    'session_fee' => (float) $session->session_fee,
                    'time_fee' => (float) $session->time_fee,
                    'energy_cost' => (float) $session->energy_cost,
                    'utility_cost' => (float) $session->utility_cost,
                    'margin' => (float) $session->margin,
                    'discount_amount' => (float) $session->discount_amount,
                ];
            });
    }
}

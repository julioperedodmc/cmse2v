<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChargingSession extends Model
{
    use HasFactory;

    protected $appends = ['billing_details'];

    protected $fillable = [
        'transaction_id',
        'station_id',
        'connector_id',
        'user_id',
        'rfid_tag_id',
        'tariff_id',
        'applied_tariff_id',
        'applied_tariff_snapshot',
        'financial_locked_at',
        'start_time',
        'stop_time',
        'meter_start',
        'meter_stop',
        'total_energy_kwh',
        'session_fee',
        'time_fee',
        'energy_cost',
        'total_cost',
        'debited_amount',
        'discount_amount',
        'utility_cost',
        'margin',
        'rate_kwh',
        'currency',
        'status',
        'stop_reason',
        'start_soc',
        'stop_soc',
        'soc_notification_sent_at',
        'item_code',
        'item_description',
        'invoice_url',
        'external_payment_id',
        'sap_synced_at',
        'product_id',
    ];

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    protected $casts = [
        'start_time' => 'datetime',
        'stop_time' => 'datetime',
        'applied_tariff_snapshot' => 'array',
        'financial_locked_at' => 'datetime',
        'soc_notification_sent_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rfidTag(): BelongsTo
    {
        return $this->belongsTo(RfidTag::class);
    }

    // Relationship to Steve Logs (Cross-Database if configured, or just standard if same DB user)
    // We use transaction_id (CMS) -> transaction_pk (Steve)
    public function meterValues()
    {
        return $this->hasMany(\App\Models\Steve\ConnectorMeterValue::class, 'transaction_pk', 'transaction_id')->orderBy('value_timestamp', 'desc');
    }

    public function sessionNotifications(): HasMany
    {
        return $this->hasMany(ChargingSessionNotification::class, 'charging_session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get a structured breakdown of the billing for the App.
     */
    public function getBillingDetailsAttribute(): array
    {
        $snapshot = $this->applied_tariff_snapshot ?? [];
        
        return [
            'total_amount' => (float) $this->total_cost,
            'parking_fee' => (float) $this->session_fee,
            'discount_amount' => (float) $this->discount_amount,
            'time_fee' => (float) $this->time_fee,
            'energy_kwh' => (float) $this->total_energy_kwh,
            'energy_cost' => (float) $this->energy_cost,
            'currency' => $this->currency ?? 'BOB',
            'breakdown' => $snapshot['billing_breakdown'] ?? [],
            'subtotal' => $snapshot['subtotal'] ?? ($this->total_cost + $this->discount_amount),
        ];
    }

    /**
     * Prepare a date for array / JSON serialization.
     * Force La Paz time so the mobile app reads it correctly without doing conversions.
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return \Carbon\Carbon::instance($date)->setTimezone('America/La_Paz')->format('Y-m-d H:i:s');
    }
}

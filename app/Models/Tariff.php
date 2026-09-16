<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Tariff extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Tariff $tariff) {
            // if ($tariff->isExpired()) {
            //     throw ValidationException::withMessages([
            //         'tariff' => 'Expired tariffs cannot be modified.',
            //     ]);
            // }

            if ($tariff->hasBeenUsed()) {
                $dirty = array_keys($tariff->getDirty());
                $allowed = [
                    'valid_until',
                    'updated_at',
                    'energy_product_id',
                    'connection_product_id',
                    'time_product_id'
                ];
                $blocked = array_values(array_diff($dirty, $allowed));

                if (!empty($blocked)) {
                    // TEMP UNLOCK FOR USER
                    // throw ValidationException::withMessages([
                    //     'tariff' => 'This tariff already has historical usage. Only "valid until" and Product IDs can be changed.',
                    // ]);
                }
            }
        });

        static::deleting(function (Tariff $tariff) {
            if ($tariff->isExpired()) {
                throw ValidationException::withMessages([
                    'tariff' => 'Expired tariffs cannot be deleted.',
                ]);
            }

            if ($tariff->hasBeenUsed()) {
                throw ValidationException::withMessages([
                    'tariff' => 'This tariff has historical usage and cannot be deleted.',
                ]);
            }
        });
    }

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_parking_fee_enabled' => 'boolean',
        'apply_fee_to_cards' => 'boolean',
        'apply_fee_to_app' => 'boolean',
        'apply_discount_to_cards' => 'boolean',
        'apply_discount_to_app' => 'boolean',
        'is_time_fee_enabled' => 'boolean',
    ];

    protected $fillable = [
        'name',
        'currency',
        'cost_price_kwh', // Base Utility Cost (Deprecated in favor of blocks, but kept for legacy)
        'price_session',
        'is_parking_fee_enabled',
        'apply_fee_to_cards',
        'apply_fee_to_app',
        'free_minutes',
        'valid_from',
        'valid_until',
        // Block 1
        'b1_start',
        'b1_end',
        'b1_price_kwh',
        'b1_cost_kwh',
        'b1_price_min',
        'is_time_fee_enabled',
        'discount_fixed_amount',
        'apply_discount_to_cards',
        'apply_discount_to_app',
        'target_soc',
        'soc_reached_message',
        // Block 2
        'b2_start',
        'b2_end',
        'b2_price_kwh',
        'b2_cost_kwh',
        'b2_price_min',
        // Block 3
        'b3_start',
        'b3_end',
        'b3_price_kwh',
        'b3_cost_kwh',
        'b3_price_min',
        // Block 4
        'b4_start',
        'b4_end',
        'b4_price_kwh',
        'b4_cost_kwh',
        'b4_price_min',
        // Product Linkage
        'energy_product_id',
        'connection_product_id',
        'time_product_id',
    ];

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class, 'tariff_id');
    }

    public function energyProduct(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'energy_product_id');
    }

    public function connectionProduct(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'connection_product_id');
    }

    public function timeProduct(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class, 'time_product_id');
    }

    public function hasBeenUsed(): bool
    {
        return $this->chargingSessions()->exists();
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && now()->greaterThan($this->valid_until);
    }

    /**
     * Identifies the current active price block based on system time.
     */
    public function getCurrentPrices(): array
    {
        // Always evaluate current time in the local timezone where tariffs are defined
        $now = now()->setTimezone('America/La_Paz')->format('H:i:s');

        for ($i = 1; $i <= 4; $i++) {
            $start = $this->{"b{$i}_start"};
            $end = $this->{"b{$i}_end"};

            if ($start && $end) {
                if ($now >= $start && $now <= $end) {
                    return [
                        'block' => $i,
                        'price_kwh' => (float) ($this->{"b{$i}_price_kwh"} ?? 0),
                        'price_min' => (float) ($this->{"b{$i}_price_min"} ?? 0),
                        'price_session' => $this->is_parking_fee_enabled ? (float) ($this->price_session ?? 0) : 0,
                        'currency' => $this->currency,
                    ];
                }
            }
        }

        // Fallback to b1
        return [
            'block' => 1,
            'price_kwh' => (float) ($this->b1_price_kwh ?? 0),
            'price_min' => (float) ($this->b1_price_min ?? 0),
            'price_session' => $this->is_parking_fee_enabled ? (float) ($this->price_session ?? 0) : 0,
            'currency' => $this->currency,
        ];
    }

    /**
     * Resolves the most appropriate tariff for a station at a given time.
     * Fallback: If no tariff is valid for the current time, use the most recently expired one.
     */
    public static function resolveForStation(?Station $station, $timestamp = null): ?self
    {
        $ts = $timestamp ? ($timestamp instanceof \Carbon\Carbon ? $timestamp : \Carbon\Carbon::parse($timestamp)) : now();

        $inWindow = function ($q) use ($ts) {
            $q->where(function ($qq) use ($ts) {
                $qq->whereNull('valid_from')->orWhere('valid_from', '<=', $ts);
            })->where(function ($qq) use ($ts) {
                $qq->whereNull('valid_until')->orWhere('valid_until', '>=', $ts);
            });
        };

        // 1. Try assigned station tariff (if within window)
        if ($station && $station->tariff_id) {
            $assigned = self::where('id', $station->tariff_id)->where($inWindow)->first();
            if ($assigned)
                return $assigned;
        }

        // 2. Try any other tariff within window
        $global = self::where($inWindow)->orderByDesc('valid_from')->first();
        if ($global)
            return $global;

        // 3. Fallback: Use the most recently expired tariff (as requested)
        $latestExpired = self::where('valid_until', '<', $ts)->orderByDesc('valid_until')->first();
        if ($latestExpired)
            return $latestExpired;

        // 4. Absolute fallback
        return self::first();
    }

    public function calculateMinBalance(float $minKwh = 5.0): float
    {
        $p = $this->getCurrentPrices();
        return (float) ($p['price_session'] + ($minKwh * $p['price_kwh']));
    }
}

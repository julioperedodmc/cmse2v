<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfidTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'tag_code',
        'user_id',
        'company_id',
        'name',
        'balance',
        'currency',
        'is_active',
        'expires_at',
        'product_id',
        'is_virtual',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'is_active' => 'boolean',
        'is_virtual' => 'boolean',
        'balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function balance(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function ($value) {
                if ($this->is_virtual) {
                    // Load wallet balance directly to avoid recursion or relationship issues
                    return (float) (\App\Models\Wallet::where('user_id', $this->user_id)->value('balance') ?? 0);
                }
                return (float) $value;
            },
        );
    }

    public function isDealership(): bool
    {
        return !empty($this->company_id) || !empty($this->user?->company_id);
    }
}

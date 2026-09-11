<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargingSessionNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'charging_session_id',
        'user_id',
        'type',
        'sent_at',
        'data',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'data' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChargingSession::class, 'charging_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

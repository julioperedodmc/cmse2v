<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements \Filament\Models\Contracts\FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, \Spatie\Permission\Traits\HasRoles;

    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        // Allow Super Admin, Enterprise Admins, and specialized staff roles
        return $this->hasRole([
            'super_admin',
            'enterprise_admin',
            'staff_admin',
            'accountant',
            'sales',
            'kiosko',
        ]);
    }

    protected static function booted()
    {
        static::deleting(function ($user) {
            // 1. Anonymize charging sessions (keep data for financial audits, but detach personal identification)
            \App\Models\ChargingSession::where('user_id', $user->id)->update([
                'user_id' => null,
                'rfid_tag_id' => null,
            ]);

            // 2. Safely manage RFID tags
            // Delete associated virtual tags
            \App\Models\RfidTag::where('user_id', $user->id)
                ->where('is_virtual', true)
                ->delete();

            // Dissociate and deactivate physical tags so they are released for future users
            \App\Models\RfidTag::where('user_id', $user->id)
                ->where('is_virtual', false)
                ->update([
                    'user_id' => null,
                    'is_active' => false,
                ]);

            // 3. Delete user's wallet (transactions cascade via DB foreign key onDelete('cascade'))
            $user->wallet()?->delete();

            // 4. Delete Sanctum API access tokens
            $user->tokens()->delete();

            // 5. Delete user's vehicles
            $user->vehicles()->delete();
        });
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vehicles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function rfidTags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RfidTag::class);
    }

    public function getBalanceAttribute()
    {
        return $this->wallet->balance ?? 0;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'billing_document',
        'billing_doc_type',
        'billing_complement',
        'billing_razon_social',
        'sap_client_code',
        'sap_synced_at',
        'fcm_token',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

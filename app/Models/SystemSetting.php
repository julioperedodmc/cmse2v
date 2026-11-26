<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_name',
        'logo_path',
        'disclaimer_text',
        'is_disclaimer_visible',
        'primary_color',
        'secondary_color',
        'button_color',
        'text_color',
        'font_family',
        'libelula_app_key',
        'libelula_invoicing_app_key',
        'invoicing_app_key',
        'libelula_api_url',
        'invoicing_policy',
        'nit_requirement_policy',
        'libelula_canal_caja',
        'libelula_canal_caja_sucursal',
        'libelula_canal_caja_usuario',
        'libelula_sector_code',
        'libelula_product_code',
        'steve_manager_url',
        'steve_manager_user',
        'steve_manager_pass',
        'invoice_on_bulk_creation',
        'billing_grace_period',
        'product_recharge_id',
        'product_energy_id',
        'product_connection_id',
        'product_penalty_id',
        'waive_parking_fee_for_cards',
        'waive_parking_fee_for_dealerships',
        'restrict_charging_without_vehicle',
        'mail_host',
        'mail_port',
        'mail_encryption',
        'mail_username',
        'mail_password',
        'mail_from_address',
        'mail_from_name',
    ];

    protected $casts = [
        'waive_parking_fee_for_cards' => 'boolean',
        'waive_parking_fee_for_dealerships' => 'boolean',
        'restrict_charging_without_vehicle' => 'boolean',
        'is_disclaimer_visible' => 'boolean',
        'invoice_on_bulk_creation' => 'boolean',
    ];

    /**
     * Singleton-like helper to get the current settings.
     */
    public static function get(): self
    {
        return self::first() ?? new self([
            'platform_name' => 'E2V Charging Network',
            'libelula_api_url' => 'https://api.libelula.bo/rest',
            'libelula_app_key' => 'a744e805-62f9-37bb-f3f6-beb3877079b8',
            'libelula_canal_caja' => '23955c77e357e4c5da69917858462130b124019b9c9f3c3b6a70b55b6e4464cd',
            'libelula_sector_code' => '31',
            'invoicing_policy' => 'recharge',
            'nit_requirement_policy' => 'optional',
        ]);
    }
}

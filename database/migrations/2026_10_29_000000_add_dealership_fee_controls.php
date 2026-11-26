<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            if (!Schema::hasColumn('tariffs', 'apply_fee_to_dealership_cards')) {
                $table->boolean('apply_fee_to_dealership_cards')->default(true)->after('apply_fee_to_cards');
            }
            if (!Schema::hasColumn('tariffs', 'apply_discount_to_dealership_cards')) {
                $table->boolean('apply_discount_to_dealership_cards')->default(false)->after('apply_discount_to_cards');
            }
        });

        Schema::table('system_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('system_settings', 'waive_parking_fee_for_dealerships')) {
                $table->boolean('waive_parking_fee_for_dealerships')->default(false)->after('waive_parking_fee_for_cards');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('tariffs', 'apply_fee_to_dealership_cards')) {
                $columns[] = 'apply_fee_to_dealership_cards';
            }
            if (Schema::hasColumn('tariffs', 'apply_discount_to_dealership_cards')) {
                $columns[] = 'apply_discount_to_dealership_cards';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('system_settings', function (Blueprint $table) {
            if (Schema::hasColumn('system_settings', 'waive_parking_fee_for_dealerships')) {
                $table->dropColumn('waive_parking_fee_for_dealerships');
            }
        });
    }
};

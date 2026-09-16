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
        Schema::create('charging_session_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('charging_session_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type', 50); // e.g. 'SOC_80', 'SOC_95', 'COMPLETED'
            $table->timestamp('sent_at')->useCurrent();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->unique(['charging_session_id', 'type'], 'cs_notif_session_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charging_session_notifications');
    }
};

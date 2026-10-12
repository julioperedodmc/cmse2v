<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('libelula_identifier')->nullable()->index();
        });

        // Recover the identificador sent to Libélula for existing transactions.
        // Internal-usage transactions (e.g. TAG-RECH-*) keep a reference that differs
        // from the identificador registered in Libélula, so it can only be found in the API log.
        DB::table('libelula_api_logs')
            ->where('endpoint', 'like', '/deuda/registrar%')
            ->whereNotNull('transaction_id')
            ->orderBy('id')
            ->each(function ($log) {
                $payload = json_decode($log->request_payload ?? '', true);
                $identifier = is_array($payload) ? ($payload['identificador'] ?? null) : null;

                if (!is_string($identifier) || $identifier === '') {
                    return;
                }

                DB::table('wallet_transactions')
                    ->where('id', $log->transaction_id)
                    ->update(['libelula_identifier' => $identifier]);
            });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['libelula_identifier']);
            $table->dropColumn('libelula_identifier');
        });
    }
};

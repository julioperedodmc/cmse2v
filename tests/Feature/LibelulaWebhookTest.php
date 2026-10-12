<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LibelulaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRecharge(array $overrides = []): object
    {
        $user = User::factory()->create();
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $user->id,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $txId = DB::table('wallet_transactions')->insertGetId(array_merge([
            'user_id' => $user->id,
            'wallet_id' => $walletId,
            'type' => 'RECHARGE',
            'amount' => 100,
            'reference_id' => 'LBE-1-20261012000000',
            'libelula_identifier' => 'LBE-1-20261012000000',
            'payment_method' => 'LIBELULA',
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return DB::table('wallet_transactions')->find($txId);
    }

    private function libelulaSays(bool $paid): void
    {
        Http::fake([
            '*/deuda/consultar' => Http::response([
                'error' => 0,
                'datos' => [['pagado' => $paid ? 1 : 0]],
            ]),
        ]);
    }

    public function test_forged_webhook_does_not_complete_unpaid_transaction(): void
    {
        $tx = $this->pendingRecharge();
        $this->libelulaSays(false);

        $this->postJson('/api/v1/mobile/webhooks/libelula', [
            'identificador' => $tx->reference_id,
            'status' => 'PAID',
            'error' => 0,
        ])->assertOk();

        $this->assertSame('PENDING', DB::table('wallet_transactions')->find($tx->id)->status);
        $this->assertEquals(0, DB::table('wallets')->find($tx->wallet_id)->balance);
    }

    public function test_webhook_without_error_field_does_not_complete_transaction(): void
    {
        $tx = $this->pendingRecharge();
        $this->libelulaSays(false);

        $this->postJson('/api/v1/mobile/webhooks/libelula', [
            'identificador' => $tx->reference_id,
        ])->assertOk();

        $this->assertSame('PENDING', DB::table('wallet_transactions')->find($tx->id)->status);
    }

    public function test_webhook_cannot_mark_transaction_as_failed(): void
    {
        $tx = $this->pendingRecharge();
        $this->libelulaSays(false);

        $this->postJson('/api/v1/mobile/webhooks/libelula', [
            'identificador' => $tx->reference_id,
            'error' => 1,
        ])->assertOk();

        $this->assertSame('PENDING', DB::table('wallet_transactions')->find($tx->id)->status);
    }

    public function test_webhook_does_not_match_transactions_by_numeric_fragments(): void
    {
        $tx = $this->pendingRecharge();
        Http::fake();

        $this->postJson('/api/v1/mobile/webhooks/libelula', [
            'identificador' => "INV-1-{$tx->id}",
        ])->assertOk();

        Http::assertNothingSent();
    }

    public function test_confirmed_payment_credits_wallet(): void
    {
        $tx = $this->pendingRecharge();
        $this->libelulaSays(true);

        $this->postJson('/api/v1/mobile/webhooks/libelula', [
            'identificador' => $tx->reference_id,
        ])->assertOk();

        $this->assertSame('COMPLETED', strtoupper(DB::table('wallet_transactions')->find($tx->id)->status));
        $this->assertEquals(100, DB::table('wallets')->find($tx->wallet_id)->balance);
    }

    public function test_internal_transaction_is_verified_with_its_libelula_identifier(): void
    {
        $tx = $this->pendingRecharge([
            'reference_id' => 'TAG-RECH-ABC123-1778356950',
            'libelula_identifier' => 'INV-1778356950-123',
        ]);
        $this->libelulaSays(true);

        $this->postJson('/api/v1/mobile/webhooks/libelula', [
            'identificador' => 'INV-1778356950-123',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request['id'] === 'INV-1778356950-123');
        $this->assertSame('COMPLETED', strtoupper(DB::table('wallet_transactions')->find($tx->id)->status));
    }
}

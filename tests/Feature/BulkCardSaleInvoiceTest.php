<?php

namespace Tests\Feature;

use App\Filament\Resources\RfidTagResource\Pages\BulkRfidManager;
use App\Models\Product;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\LibelulaPaymentService;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * H30: a card sale from the panel must never be erased because Libélula failed or timed out,
 * and retrying must reuse the same identificador so Libélula cannot issue a second invoice.
 */
class BulkCardSaleInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        // Keep the SteVe tag sync away from any real database.
        config(['database.connections.steve' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('client', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    private function products(): array
    {
        $card = Product::create(['name' => 'TARJETA PREPAGO', 'internal_code' => 'CARD', 'siat_product_code' => '99100']);
        $recharge = Product::create(['name' => 'RECARGA SALDO', 'internal_code' => 'RECHARGE', 'siat_product_code' => '99101']);

        return [$card->id, $recharge->id];
    }

    private function submitSale(array $codes, string $assignment = 'corporate', string $paymentMethod = 'manual'): void
    {
        [$cardProductId, $rechargeProductId] = $this->products();

        Livewire::test(BulkRfidManager::class)
            ->fillForm([
                'tag_codes' => implode("\n", $codes),
                'card_product_id' => $cardProductId,
                'card_price' => 50,
                'card_discount' => 0,
                'recharge_product_id' => $rechargeProductId,
                'credit_amount' => 100,
                'recharge_discount' => 0,
                'assignment_type' => $assignment,
                'new_user_name' => 'Concesionaria Demo',
                'new_user_email' => 'flota-demo@example.com',
                'emit_invoice' => true,
                'payment_method' => $paymentMethod,
                'global_discount' => 0,
            ])
            ->call('submit');
    }

    private function saleTransactions()
    {
        return WalletTransaction::where('description', 'like', '%tarjeta%')->orderBy('id')->get();
    }

    public function test_timeout_keeps_the_sale_and_marks_the_invoice_as_uncertain(): void
    {
        Http::fake(fn() => throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds'));

        $this->submitSale(['AAAA0001', 'AAAA0002']);

        $this->assertSame(2, RfidTag::count(), 'Las tarjetas deben quedar creadas aunque Libélula no responda.');

        $tx = $this->saleTransactions()->sole();
        $this->assertSame('PENDING', $tx->status);
        $this->assertSame('BULK-' . $tx->id, $tx->libelula_identifier);
        $this->assertSame(LibelulaPaymentService::INVOICE_UNCERTAIN, $tx->metadata['invoice_state']);
        $this->assertTrue($tx->metadata['skip_wallet_update'], 'El error no debe borrar la marca que evita abonar dos veces.');
        $this->assertCount(4, $tx->metadata['line_items']);
    }

    public function test_libelula_rejection_after_invoicing_does_not_erase_the_sale(): void
    {
        Http::fake(['*/deuda/registrar' => Http::response(['error' => 1, 'mensaje' => 'Servicio de impuestos no disponible'])]);

        $this->submitSale(['BBBB0001']);

        $this->assertSame(1, RfidTag::count());
        $tx = $this->saleTransactions()->sole();
        $this->assertSame('PENDING', $tx->status);
        $this->assertSame(LibelulaPaymentService::INVOICE_UNCERTAIN, $tx->metadata['invoice_state']);
        $this->assertSame('Servicio de impuestos no disponible', $tx->metadata['invoice_error']);
        $this->assertSame(1, DB::table('libelula_api_logs')->count(), 'El registro de la llamada a Libélula debe conservarse.');
    }

    public function test_retry_reuses_the_identifier_and_does_not_credit_the_wallet(): void
    {
        $identifiers = [];
        $responses = [
            Http::response(['error' => 1, 'mensaje' => 'Timeout SIAT']),
            // Libélula had registered it after all: it answers "already exists".
            Http::response(['error' => 2, 'mensaje' => 'La deuda ya existe']),
        ];
        Http::fake(function (Request $request) use (&$identifiers, &$responses) {
            $identifiers[] = $request['identificador'];
            return array_shift($responses);
        });

        $this->submitSale(['CCCC0001']);
        $tx = $this->saleTransactions()->sole();
        $walletBalance = (float) $tx->wallet->fresh()->balance;

        $result = app(LibelulaPaymentService::class)->issueStoredInvoice($tx);

        $this->assertTrue($result['success']);
        $this->assertSame(['BULK-' . $tx->id, 'BULK-' . $tx->id], $identifiers);

        $tx->refresh();
        $this->assertSame('COMPLETED', $tx->status);
        $this->assertSame(LibelulaPaymentService::INVOICE_ISSUED, $tx->metadata['invoice_state']);
        $this->assertEquals($walletBalance, (float) $tx->wallet->fresh()->balance, 'El saldo ya está en las tarjetas: la billetera no se abona.');
        $this->assertEquals(100, (float) RfidTag::sole()->balance);

        // A further retry does not call Libélula again.
        app(LibelulaPaymentService::class)->issueStoredInvoice($tx);
        $this->assertCount(2, $identifiers);
    }

    public function test_individual_sale_failure_on_one_card_keeps_the_others(): void
    {
        $responses = [
            Http::response(['error' => 0, 'facturas_electronicas' => [['url' => 'https://pagos.libelula.bo/factura/abc']]]),
            Http::response(['error' => 1, 'mensaje' => 'Servicio de impuestos no disponible']),
        ];
        Http::fake(function () use (&$responses) {
            return array_shift($responses);
        });

        $this->submitSale(['DDDD0001', 'DDDD0002'], 'individual');

        $this->assertSame(2, RfidTag::count());
        [$first, $second] = $this->saleTransactions()->all();
        $this->assertSame(LibelulaPaymentService::INVOICE_ISSUED, $first->metadata['invoice_state']);
        $this->assertSame('https://pagos.libelula.bo/factura/abc', $first->invoice_url);
        $this->assertSame(LibelulaPaymentService::INVOICE_UNCERTAIN, $second->metadata['invoice_state']);
    }
}

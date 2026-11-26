<?php

namespace App\Filament\Resources\RfidTagResource\Pages;

use App\Filament\Resources\RfidTagResource;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Company;
use App\Models\WalletTransaction;
use App\Services\LibelulaPaymentService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BulkRfidManager extends Page
{
    protected static string $resource = RfidTagResource::class;

    protected static string $view = 'filament.resources.rfid-tag-resource.pages.bulk-rfid-manager';

    protected static ?string $title = 'Bulk RFID Manager';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Configuración del Lote')
                    ->description('Ingrese los códigos RFID y configure el destino (Empresa/Usuario).')
                    ->schema([
                        Textarea::make('tag_codes')
                            ->label('Códigos RFID (Uno por línea)')
                            ->placeholder("55778899\nAABBCCDD")
                            ->required()
                            ->rows(8),

                        Section::make('Detalles de la Tarjeta Física')
                            ->schema([
                                Select::make('card_product_id')
                                    ->label('Producto de Venta (Tarjeta)')
                                    ->options(\App\Models\Product::pluck('name', 'id'))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Producto SIAT que representa el plástico de la tarjeta.'),
                                TextInput::make('card_price')
                                    ->label('Precio de la Tarjeta (BOB)')
                                    ->numeric()
                                    ->default(1)
                                    ->required()

                                    ->live(),
                                TextInput::make('card_discount')
                                    ->label('Descuento de Tarjeta (BOB)')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->live()
                                    ->helperText('Si el descuento es igual al precio, el cliente no paga por la tarjeta, pero aparece en la factura.'),
                            ])->columns(3),

                        Section::make('Detalles de Recarga Inicial')
                            ->schema([
                                Select::make('recharge_product_id')
                                    ->label('Producto de Recarga')
                                    ->options(\App\Models\Product::pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->helperText('Producto SIAT que representa el servicio de recarga de saldo.'),
                                TextInput::make('credit_amount')
                                    ->label('Saldo a Recargar (BOB)')
                                    ->numeric()
                                    ->default(0)
                                    ->required(),
                                TextInput::make('recharge_discount')
                                    ->label('Descuento de Recarga (BOB)')
                                    ->numeric()
                                    ->default(0)
                                    ->required(),
                            ])->columns(3),

                        Select::make('assignment_type')
                            ->label('Tipo de Asignación')
                            ->options([
                                'individual' => 'Un usuario nuevo por cada tarjeta',
                                'corporate' => 'Un solo usuario corporativo para todo el lote',
                                'existing' => 'Vincular a un usuario existente',
                            ])
                            ->default('individual')
                            ->required()
                            ->live(),

                        Section::make('Detalles del Usuario Corporativo / Empresa')
                            ->schema([
                                Select::make('company_id')
                                    ->label('Empresa')
                                    ->options(Company::all()->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state) {
                                            $company = Company::find($state);
                                            if ($company) {
                                                // Prioritize loading the existing user already assigned to this company
                                                $existingUser = User::where('company_id', $state)->first();

                                                if ($existingUser) {
                                                    $set('new_user_name', $existingUser->name);
                                                    $set('new_user_email', $existingUser->email);
                                                    $set('billing_razon_social', $existingUser->billing_razon_social ?: $company->name);
                                                    $set('billing_document', $existingUser->billing_document ?: $company->tax_id);
                                                } else {
                                                    $set('new_user_name', $company->name);
                                                    $set('new_user_email', $company->email);
                                                    $set('billing_razon_social', $company->name);
                                                    $set('billing_document', $company->tax_id);
                                                }
                                            }
                                        }
                                    }),
                                TextInput::make('new_user_name')
                                    ->label('Nombre del Usuario/Flota')
                                    ->required()
                                    ->placeholder('Ej: Flota Trans-Vía'),
                                TextInput::make('new_user_email')
                                    ->label('Correo Electrónico')
                                    ->email()
                                    ->required()
                                    ->helperText('Si la empresa ya tiene un usuario registrado, se utilizará su cuenta para vincular el lote.'),
                                TextInput::make('billing_razon_social')
                                    ->label('Razón Social'),
                                TextInput::make('billing_document')
                                    ->label('NIT / CI'),
                            ])
                            ->visible(fn(callable $get) => $get('assignment_type') === 'corporate')
                            ->columns(2),

                        Select::make('user_id')
                            ->label('Usuario Existente')
                            ->options(User::all()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->visible(fn(callable $get) => $get('assignment_type') === 'existing')
                            ->required(fn(callable $get) => $get('assignment_type') === 'existing'),

                        Section::make('Facturación y Pago')
                            ->schema([
                                Toggle::make('emit_invoice')
                                    ->label('Emitir Factura Oficial (Libélula)')
                                    ->default(fn() => \App\Models\SystemSetting::get()->invoice_on_bulk_creation)
                                    ->live(),

                                TextInput::make('vehicle_plate')
                                    ->label('Placa de Vehículo')
                                    ->default('1111ABC')
                                    ->helperText('Placa a reportar en la factura del lote. Se permite ingresar "0000000" u otro formato de placa.')
                                    ->visible(fn(callable $get) => $get('emit_invoice')),

                                Select::make('payment_method')
                                    ->label('Método de Pago')
                                    ->options(fn(callable $get) => $get('emit_invoice') ? [
                                        'manual' => 'Efectivo / Manual (Caja)',
                                        'libelula' => 'Pasarela de Pago (QR/Tarjeta)',
                                        'credit' => 'A Crédito (Activa saldo de inmediato)',
                                    ] : [
                                        'manual' => 'Efectivo / Manual (Caja)',
                                        'credit' => 'A Crédito (Activa saldo de inmediato)',
                                    ])
                                    ->default('manual')
                                    ->required(),

                                TextInput::make('global_discount')
                                    ->label('Descuento Global (BOB)')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->helperText('Descuento adicional aplicado al total de la factura (SIAT).'),
                            ])->columns(2),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $inputData = $this->form->getState();
        $codes = array_filter(array_map('trim', explode("\n", $inputData['tag_codes'])));
        $credit = (float) ($inputData['credit_amount'] ?? 0);
        $cardPrice = (float) ($inputData['card_price'] ?? 0);
        $cardDiscount = (float) ($inputData['card_discount'] ?? 0);
        $assignmentType = $inputData['assignment_type'];
        $companyId = $inputData['company_id'] ?? null;
        $selectedUserId = $inputData['user_id'] ?? null;
        $cardProductId = $inputData['card_product_id'];
        $rechargeProductId = $inputData['recharge_product_id'];

        $rechargeDiscount = (float) ($inputData['recharge_discount'] ?? 0);
        $globalDiscount = (float) ($inputData['global_discount'] ?? 0);
        $totalToPay = max(0, $cardPrice - $cardDiscount) + max(0, $credit - $rechargeDiscount) - $globalDiscount;
        $totalToPay = max(0, $totalToPay); // Ensure not negative

        if (empty($codes)) {
            Notification::make()->title('No se proporcionaron códigos')->danger()->send();
            return;
        }

        $emitInvoice = (bool) ($inputData['emit_invoice'] ?? false);
        $paymentMethod = $inputData['payment_method'] ?? 'manual';
        $isManual = $paymentMethod === 'manual';
        $isCredit = $paymentMethod === 'credit';
        $vehiclePlate = $inputData['vehicle_plate'] ?? '1111ABC';

        $createdCount = 0;
        $errorCount = 0;
        // Sale transactions whose invoice is requested from Libélula AFTER the sale is committed.
        $invoiceTxIds = [];

        // Step 1: register the sale (users, tags and sale transactions) and commit it.
        // Libélula is called only afterwards, so an error or timeout there can no longer
        // erase a sale that Libélula may already have invoiced.
        DB::beginTransaction();
        try {
            $sharedUserId = null;

            // Scenario: Single corporate user for the whole batch
            if ($assignmentType === 'corporate') {
                $sharedUser = null;
                if ($companyId) {
                    $sharedUser = User::where('company_id', $companyId)->first();
                }

                if (!$sharedUser && !empty($inputData['new_user_email'])) {
                    $sharedUser = User::where('email', $inputData['new_user_email'])->first();
                }

                if ($sharedUser) {
                    $updateData = [];
                    if (!empty($inputData['billing_razon_social'])) {
                        $updateData['billing_razon_social'] = $inputData['billing_razon_social'];
                    }
                    if (!empty($inputData['billing_document'])) {
                        $updateData['billing_document'] = $inputData['billing_document'];
                    }
                    if ($companyId && !$sharedUser->company_id) {
                        $updateData['company_id'] = $companyId;
                    }
                    if (!empty($updateData)) {
                        $sharedUser->update($updateData);
                    }
                } else {
                    $targetEmail = $inputData['new_user_email'];
                    if (User::where('email', $targetEmail)->exists()) {
                        $company = Company::find($companyId);
                        $slug = Str::slug($company?->name ?? 'empresa');
                        $targetEmail = "corp_{$slug}_{$companyId}@dmc.bo";
                    }

                    $sharedUser = User::create([
                        'name' => $inputData['new_user_name'],
                        'email' => $targetEmail,
                        'password' => Hash::make(Str::random(12)),
                        'billing_razon_social' => $inputData['billing_razon_social'] ?? null,
                        'billing_document' => $inputData['billing_document'] ?? null,
                        'company_id' => $companyId,
                        'is_admin' => false,
                    ]);
                }

                if (!$sharedUser->hasRole('client')) {
                    $sharedUser->assignRole('client');
                }
                $sharedUserId = $sharedUser->id;
            } elseif ($assignmentType === 'existing' && $selectedUserId) {
                $existingUser = User::find($selectedUserId);
                if ($existingUser && !$companyId) {
                    $companyId = $existingUser->company_id;
                }
            }

            $cardProduct = \App\Models\Product::find($cardProductId);
            $rechargeProduct = \App\Models\Product::find($rechargeProductId);

            $cardProductName = $cardProduct?->name ?? 'TARJETA PREPAGO';
            $cardSiatCode = $cardProduct?->siat_product_code ?: ($cardProduct?->internal_code ?: '1');

            $rechargeProductName = $rechargeProduct?->name ?? 'RECARGA SALDO TARJETA PREPAGO';
            $rechargeSiatCode = $rechargeProduct?->siat_product_code ?: ($rechargeProduct?->internal_code ?: '1');

            $allLineItems = [];
            $totalBatchAmount = 0;
            $tagCodesList = [];

            foreach ($codes as $code) {
                // Standardize to 8 characters (remove colons, padding, etc)
                $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

                if (strlen($code) > 8) {
                    $code = substr($code, -8);
                }

                if (RfidTag::where('tag_code', $code)->exists()) {
                    $errorCount++;
                    continue;
                }

                $userId = $selectedUserId;

                if ($assignmentType === 'individual') {
                    // One new user per tag (or reuse if already exists)
                    $user = User::firstOrCreate(
                        ['email' => "rfid_$code@evce.temp"],
                        [
                            'name' => "Usuario RFID $code",
                            'password' => Hash::make(Str::random(12)),
                            'company_id' => $companyId,
                            'is_admin' => false,
                        ]
                    );
                    if ($companyId && !$user->company_id) {
                        $user->update(['company_id' => $companyId]);
                    }
                    if (!$user->hasRole('client')) {
                        $user->assignRole('client');
                    }
                    $userId = $user->id;
                } elseif ($assignmentType === 'corporate') {
                    $userId = $sharedUserId;
                }

                // Create RFID Tag
                $tag = RfidTag::create([
                    'tag_code' => $code,
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'product_id' => $cardProductId,
                    'name' => "Tarjeta $code",
                    'balance' => $credit,
                    'currency' => 'BOB',
                    'is_active' => true,
                ]);

                // Prepare Line Items for this specific tag
                $tagLineItems = [];
                $tagTotal = 0;

                if ($cardPrice > 0 || $cardDiscount > 0) {
                    $tagLineItems[] = [
                        'concepto' => "{$cardProductName} ($code)",
                        'cantidad' => (int) 1,
                        'costo_unitario' => $cardPrice,
                        'descuento_unitario' => $cardDiscount,
                        'detalle' => "{$cardProductName} ($code)",
                        'codigo_producto' => (string) $cardSiatCode,
                        'ignora_factura' => false,
                    ];
                    $tagTotal += max(0, $cardPrice - $cardDiscount);
                }

                if ($credit > 0) {
                    $tagLineItems[] = [
                        'concepto' => "{$rechargeProductName} ($code)",
                        'cantidad' => (int) 1,
                        'costo_unitario' => $credit,
                        'descuento_unitario' => $rechargeDiscount,
                        'detalle' => "{$rechargeProductName} ($code)",
                        'codigo_producto' => (string) $rechargeSiatCode,
                        'ignora_factura' => false,
                    ];
                    $tagTotal += max(0, $credit - $rechargeDiscount);
                }

                // If it's the SAME user (corporate/existing), we group line items for a single master TX
                if ($assignmentType !== 'individual') {
                    $allLineItems = array_merge($allLineItems, $tagLineItems);
                    $totalBatchAmount += $tagTotal;
                    $tagCodesList[] = $code;
                } else {
                    // FOR INDIVIDUAL USERS: We MUST emit separate invoices AND separate TXs (different clients)
                    if ($userId) {
                        $wallet = Wallet::firstOrCreate(['user_id' => $userId], ['balance' => 0, 'currency' => 'BOB']);
                        $tx = WalletTransaction::create([
                            'user_id' => $userId,
                            'wallet_id' => $wallet->id,
                            'type' => 'RECHARGE',
                            'amount' => $tagTotal,
                            'balance_after' => $tag->balance,
                            'currency' => 'BOB',
                            'reference_id' => 'BULK-' . $code . '-' . now()->timestamp,
                            'status' => 'PENDING',
                            'description' => " Venta tarjeta RFID $code",
                            'payment_method' => $isManual ? 'MANUAL_CASH' : ($isCredit ? 'CREDITO' : 'LIBELULA'),
                            'metadata' => [
                                'rfid_tag' => $code,
                                'line_items' => $tagLineItems,
                                'should_invoice' => $emitInvoice,
                                'skip_wallet_update' => true, // Since the tag was already credited
                            ]
                        ]);

                        if ($emitInvoice) {
                            $this->registerInvoiceRequest($tx, $tagTotal, "Carga inicial RFID $code", $tagLineItems, 0, $isManual, $isCredit, $vehiclePlate);
                            $invoiceTxIds[] = $tx->id;
                        }
                    }
                }

                $createdCount++;
            }

            // FINAL STEP: Create ONE Master Transaction for corporate/existing users
            if ($assignmentType !== 'individual' && !empty($allLineItems) && $userId) {
                $wallet = Wallet::firstOrCreate(['user_id' => $userId], ['balance' => 0, 'currency' => 'BOB']);

                // One single transaction for the whole batch
                $masterTx = WalletTransaction::create([
                    'user_id' => $userId,
                    'wallet_id' => $wallet->id,
                    'type' => 'RECHARGE',
                    'amount' => max(0, $totalBatchAmount - $globalDiscount),
                    'balance_after' => $wallet->balance,
                    'currency' => 'BOB',
                    'reference_id' => 'BULK-BATCH-' . now()->timestamp,
                    'status' => 'PENDING',
                    'description' => "Venta lote de " . count($tagCodesList) . " tarjetas RFID",
                    'payment_method' => $isManual ? 'MANUAL_CASH' : ($isCredit ? 'CREDITO' : 'LIBELULA'),
                    'metadata' => [
                        'tag_codes' => $tagCodesList,
                        'line_items' => $allLineItems,
                        'global_discount' => $globalDiscount,
                        'should_invoice' => $emitInvoice,
                        'skip_wallet_update' => true, // CRITICAL: Balance is already on tags!
                    ]
                ]);

                if ($emitInvoice) {
                    $this->registerInvoiceRequest($masterTx, (float) $masterTx->amount, $masterTx->description, $allLineItems, $globalDiscount, $isManual, $isCredit, $vehiclePlate);
                    $invoiceTxIds[] = $masterTx->id;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Notification::make()
                ->title('Error en el proceso')
                ->body('No se creó ninguna tarjeta ni se pidió ninguna factura: ' . $e->getMessage())
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        // Step 2: request the invoices. Each one is independent and keeps a stable identificador,
        // so a failure leaves that sale registered with its invoice "en duda" instead of erasing it.
        $issued = 0;
        $uncertain = [];
        if (!empty($invoiceTxIds)) {
            $libService = app(LibelulaPaymentService::class);
            foreach ($invoiceTxIds as $txId) {
                $tx = WalletTransaction::find($txId);
                $result = $libService->issueStoredInvoice($tx);
                if ($result['success']) {
                    $issued++;
                } else {
                    $uncertain[] = "#{$txId}: " . ($result['detail'] ?? $result['message'] ?? 'Error desconocido');
                }
            }
        }

        $bodyMessage = "Se crearon $createdCount tarjetas. $errorCount omitidas.";
        if ($emitInvoice) {
            if ($isManual) {
                $bodyMessage .= " Facturas emitidas: $issued.";
            } elseif ($isCredit) {
                $bodyMessage .= " Tarjetas a crédito. Facturas emitidas: $issued.";
            } else {
                $bodyMessage .= " Enlaces de pago generados en Libélula: $issued.";
            }
        }

        if (!empty($uncertain)) {
            Notification::make()
                ->title('Tarjetas creadas; factura en duda')
                ->body($bodyMessage . ' Libélula no confirmó ' . count($uncertain) . ' factura(s). Pudo haberla emitido igual: '
                    . 'no vuelvas a crear las tarjetas. Usa "Reintentar factura" en Movimientos de billetera, que reutiliza el mismo identificador. '
                    . implode(' | ', $uncertain))
                ->warning()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title('Proceso completado')
                ->body($bodyMessage)
                ->success()
                ->send();
        }

        $this->form->fill();
    }

    /**
     * Stores everything needed to request (and later retry) the invoice of a sale transaction,
     * with an identificador that never changes between attempts.
     */
    private function registerInvoiceRequest(
        WalletTransaction $tx,
        float $amount,
        string $description,
        array $lineItems,
        float $discount,
        bool $isManual,
        bool $isCredit,
        string $vehiclePlate
    ): void {
        $identifier = 'BULK-' . $tx->id;

        $metadata = $tx->metadata ?? [];
        $metadata['invoice_state'] = LibelulaPaymentService::INVOICE_PENDING;
        $metadata['invoice_request'] = [
            'identifier' => $identifier,
            'amount' => round($amount, 2),
            'description' => $description,
            'line_items' => $lineItems,
            'discount' => $discount,
            'is_paid' => $isManual,
            'is_credit' => $isCredit,
            'vehicle_plate' => $vehiclePlate,
        ];

        $attributes = ['metadata' => $metadata];
        if (\Illuminate\Support\Facades\Schema::hasColumn('wallet_transactions', 'libelula_identifier')) {
            $attributes['libelula_identifier'] = $identifier;
        }

        $tx->forceFill($attributes)->save();
    }
}

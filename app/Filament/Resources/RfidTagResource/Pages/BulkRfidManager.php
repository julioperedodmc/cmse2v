<?php

namespace App\Filament\Resources\RfidTagResource\Pages;

use App\Filament\Resources\RfidTagResource;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Company;
use App\Models\WalletTransaction;
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
                                                $set('new_user_name', $company->name);
                                                $set('new_user_email', $company->email);
                                                $set('billing_razon_social', $company->name);
                                                $set('billing_document', $company->tax_id);
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
                                    ->required(),
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
                                    ->visible(fn (callable $get) => $get('emit_invoice')),
                                
                                Select::make('payment_method')
                                    ->label('Método de Pago')
                                    ->options(fn (callable $get) => $get('emit_invoice') ? [
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

        $createdCount = 0;
        $errorCount = 0;

        DB::beginTransaction();
        try {
            $sharedUserId = null;

            // Scenario: Single corporate user for the whole batch
            if ($assignmentType === 'corporate') {
                $sharedUser = User::create([
                    'name' => $inputData['new_user_name'],
                    'email' => $inputData['new_user_email'],
                    'password' => Hash::make(Str::random(12)),
                    'billing_razon_social' => $inputData['billing_razon_social'],
                    'billing_document' => $inputData['billing_document'],
                    'company_id' => $companyId,
                    'is_admin' => false,
                ]);
                $sharedUser->assignRole('client');
                $sharedUserId = $sharedUser->id;
            }

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
                    // One new user per tag
                    $user = User::create([
                        'name' => "Usuario RFID $code",
                        'email' => "rfid_$code@evce.temp",
                        'password' => Hash::make(Str::random(12)),
                        'company_id' => $companyId,
                        'is_admin' => false,
                    ]);
                    $user->assignRole('client');
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
                    $cardSiatCode = \App\Models\Product::find($cardProductId)?->siat_product_code ?? '1';
                    $tagLineItems[] = [
                        'concepto' => " RFID ($code) monto Bs " . number_format($cardPrice, 2),
                        'cantidad' => (int) 1,
                        'costo_unitario' => $cardPrice,
                        'descuento_unitario' => $cardDiscount,
                        'detalle' => " Tarjeta Plástica NFC Cod: $code",
                        'codigo_producto' => $cardSiatCode,
                        'ignora_factura' => false,
                    ];
                    $tagTotal += max(0, $cardPrice - $cardDiscount);
                }
                
                if ($credit > 0) {
                    $rechargeSiatCode = \App\Models\Product::find($rechargeProductId)?->siat_product_code ?? '1';
                    $tagLineItems[] = [
                        'concepto' => " ($code) monto Bs " . number_format($credit, 2),
                        'cantidad' => (int) 1,
                        'costo_unitario' => $credit,
                        'descuento_unitario' => $rechargeDiscount,
                        'detalle' => " Recarga inicial tarjeta NFC Cod: $code",
                        'codigo_producto' => $rechargeSiatCode,
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
                        $isManual = ($inputData['payment_method'] ?? 'manual') === 'manual';
                        $isCredit = ($inputData['payment_method'] ?? 'manual') === 'credit';
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
                                'should_invoice' => $inputData['emit_invoice'] ?? false,
                                'skip_wallet_update' => true, // Since the tag was already credited
                            ]
                        ]);

                        // Emit invoice if checked. For credit, we want to invoice immediately but keep status as credit.
                        if ($inputData['emit_invoice'] ?? false) {
                            $isCredit = ($inputData['payment_method'] ?? 'manual') === 'credit';
                            $isManual = ($inputData['payment_method'] ?? 'manual') === 'manual';
                            
                            // For manual and credit, we want to emit invoice immediately (isPaid = true for manual, but for credit we pass is_credit = true)
                            $libService = app(\App\Services\LibelulaPaymentService::class);
                             $result = $libService->createPayment($wallet, $tagTotal, "Carga inicial RFID $code", [
                                 'emite_factura' => true,
                                 'internal_usage_tx' => true,
                                 'transaction_id' => $tx->id,
                                 'line_items' => $tagLineItems,
                                 'is_credit' => $isCredit,
                                 'vehicle_plate' => $inputData['vehicle_plate'] ?? '1111ABC',
                             ], $isManual, 0);

                            if (!$result['success']) {
                                throw new \Exception("Libélula (Tag $code): " . ($result['detail'] ?? $result['message']));
                            }
                        }
                    }
                }

                $createdCount++;
            }

            // FINAL STEP: Create ONE Master Transaction for corporate/existing users
            if ($assignmentType !== 'individual' && !empty($allLineItems) && $userId) {
                $isManual = ($inputData['payment_method'] ?? 'manual') === 'manual';
                $isCredit = ($inputData['payment_method'] ?? 'manual') === 'credit';
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
                        'should_invoice' => $inputData['emit_invoice'] ?? false,
                        'skip_wallet_update' => true, // CRITICAL: Balance is already on tags!
                    ]
                ]);

                // Emit invoice if checked. For credit, we want to invoice immediately but keep status as credit.
                if ($inputData['emit_invoice'] ?? false) {
                    $libService = app(\App\Services\LibelulaPaymentService::class);
                     $result = $libService->createPayment($wallet, $masterTx->amount, $masterTx->description, [
                         'emite_factura' => true,
                         'internal_usage_tx' => true,
                         'transaction_id' => $masterTx->id,
                         'line_items' => $allLineItems,
                         'is_credit' => $isCredit,
                         'vehicle_plate' => $inputData['vehicle_plate'] ?? '1111ABC',
                     ], $isManual, $globalDiscount);

                    if (!$result['success']) {
                        throw new \Exception("Libélula (Lote): " . ($result['detail'] ?? $result['message']));
                    }
                }
            }

            DB::commit();

            $bodyMessage = "Se crearon $createdCount tarjetas. $errorCount omitidas.";
            if ($inputData['emit_invoice'] ?? false) {
                if ($isManual) {
                    $bodyMessage = "Tarjetas creadas. Recarga y Factura procesadas.";
                } elseif ($isCredit) {
                    $bodyMessage = "Tarjetas creadas a crédito y Facturas emitidas.";
                } else {
                    $bodyMessage = "Tarjetas creadas. Enlaces de pago generados en Libélula.";
                }
            }
            
            Notification::make()
                ->title('Proceso completado')
                ->body($bodyMessage)
                ->success()
                ->send();

            $this->form->fill();

        } catch (\Exception $e) {
            DB::rollBack();
            Notification::make()
                ->title('Error en el proceso')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}

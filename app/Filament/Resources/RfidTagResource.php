<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RfidTagResource\Pages;
use App\Filament\Resources\RfidTagResource\Pages\BulkRfidManager;
use App\Filament\Resources\RfidTagResource\RelationManagers;
use App\Models\RfidTag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Filament\Notifications\Notification;
use App\Models\WalletTransaction;
use App\Models\Wallet;
use App\Models\SystemSetting;

class RfidTagResource extends Resource
{
    protected static ?string $model = RfidTag::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Operaciones';
    protected static ?string $modelLabel = 'Tarjeta RFID';
    protected static ?string $pluralModelLabel = 'Tarjetas RFID';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && $user->hasRole('enterprise_admin')) {
            return $query->where('company_id', $user->company_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('tag_code')
                    ->label('RFID Tag Code (8 chars)')
                    ->required()
                    ->maxLength(8)
                    ->helperText('Standard 4-byte UID hex (8 characters).')
                    // Auto-clean colons and non-alphanumeric chars on blur
                    ->afterStateUpdated(fn($state, $set) => $set('tag_code', substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $state)), -8)))
                    ->live(onBlur: true),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->default(null),
                Forms\Components\Select::make('company_id')
                    ->relationship('company', 'name')
                    ->default(null),
                Forms\Components\TextInput::make('name')
                    ->maxLength(255)
                    ->disabled(fn($record) => $record?->is_virtual)
                    ->default(null),
                Forms\Components\Select::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\DatePicker::make('expires_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tag_code')
                    ->label('Código de Tarjeta')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('company.name')
                    ->label('Empresa')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo (BOB)')
                    ->money('BOB')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
                Tables\Columns\TextColumn::make('is_virtual')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state ? 'Virtual' : 'Física')
                    ->color(fn($state) => $state ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Fecha de Expiración')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('manual_recharge')
                    ->label('Recarga Manual')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('warning')
                    ->visible(fn() => auth()->user()?->hasAnyRole(['super_admin', 'staff_admin', 'sales']))
                    ->form([
                        Forms\Components\Select::make('payment_method')
                            ->label('Método de Pago')
                            ->options(fn(callable $get) => $get('emit_invoice') ? [
                                'manual' => 'Efectivo / Manual (Caja)',
                                'libelula' => 'Pasarela de Pago (Libélula QR/Tarjeta)',
                                'credit' => 'A Crédito (Activa saldo de inmediato)',
                            ] : [
                                'manual' => 'Efectivo / Manual (Caja)',
                                'credit' => 'A Crédito (Activa saldo de inmediato)',
                            ])
                            ->required()
                            ->default('manual')
                            ->live(),
                        Forms\Components\Select::make('product_id')
                            ->label('Producto SIAT')
                            ->options(\App\Models\Product::pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn() => \App\Models\Product::where('siat_product_code', '99')->first()?->id)
                            ->helperText('Producto bajo el cual se facturará esta recarga.')
                            ->visible(fn(Forms\Get $get) => $get('emit_invoice'))
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, $state, Forms\Get $get) {
                                if (!$get('custom_description') && $state) {
                                    $product = \App\Models\Product::find($state);
                                    if ($product) {
                                        $set('description', $product->name);
                                    }
                                }
                            }),
                        Forms\Components\Toggle::make('custom_description')
                            ->label('Personalizar descripción')
                            ->default(false)
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, $state, Forms\Get $get) {
                                if (!$state) {
                                    $productId = $get('product_id') ?? \App\Models\Product::where('siat_product_code', '99')->first()?->id;
                                    if ($productId) {
                                        $product = \App\Models\Product::find($productId);
                                        if ($product) {
                                            $set('description', $product->name);
                                        }
                                    }
                                }
                            }),
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto a Recargar')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->default(10)
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, $state, Forms\Get $get) {
                                if (!$get('custom_description')) {
                                    $set('description', 'Recarga de tarjeta RFID - Bs ' . number_format((float) $state, 2));
                                }
                            }),
                        Forms\Components\TextInput::make('description')
                            ->label('Motivo / Descripción')
                            ->required()
                            ->maxLength(255)
                            ->default('Recarga de tarjeta RFID - Bs 10.00')
                            ->disabled(fn(Forms\Get $get) => !$get('custom_description'))
                            ->dehydrated(),
                        Forms\Components\TextInput::make('global_discount')
                            ->label('Descuento Global (BOB)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Monto a descontar en el total de la factura oficial.'),
                        Forms\Components\Toggle::make('emit_invoice')
                            ->label('Emitir Factura Oficial (Libélula)')
                            ->default(fn() => SystemSetting::get()->invoicing_policy === 'recharge')
                            ->helperText('Requiere que la tarjeta esté asignada a un usuario con datos de facturación.')
                            ->live(),
                    ])
                    ->action(function (RfidTag $record, array $data): void {
                        $amount = round((float) $data['amount'], 2);

                        if ($data['emit_invoice'] && !$record->user_id) {
                            Notification::make()
                                ->title('Error de Facturación')
                                ->body('No se puede emitir factura si la tarjeta no está asignada a un usuario.')
                                ->danger()
                                ->send();
                            return;
                        }

                        DB::transaction(function () use ($record, $amount, $data) {
                            $isManual = ($data['payment_method'] === 'manual');
                            $isCredit = ($data['payment_method'] === 'credit');
                            $shouldIncrementBalance = $isManual || $isCredit;

                            // 1. Update Tag balance ONLY IF it's a manual cash payment or a credit payment (balance is active immediately)
                            if ($shouldIncrementBalance) {
                                if (!$record->is_virtual) {
                                    $record->increment('balance', $amount);
                                } else {
                                    $wallet = Wallet::firstOrCreate(
                                        ['user_id' => $record->user_id],
                                        ['currency' => 'BOB', 'balance' => 0]
                                    );
                                    $wallet->increment('balance', $amount);
                                }
                            }

                            // 2. Create Transaction Record for history and invoicing
                            $user = $record->user;
                            if ($user) {
                                $wallet = $user->wallet ?: $user->wallet()->create(['currency' => 'BOB', 'balance' => 0]);
                                $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';

                                $tx = WalletTransaction::create([
                                    'wallet_id' => $wallet->id,
                                    'user_id' => $user->id,
                                    'type' => 'RECHARGE',
                                    'amount' => $amount,
                                    'balance_after' => $shouldIncrementBalance ? ($record->is_virtual ? $wallet->balance : $record->balance) : $record->balance,
                                    'currency' => $wallet->currency ?? 'BOB',
                                    $refCol => 'TAG-RECH-' . $record->tag_code . '-' . now()->timestamp,
                                    'description' => $data['description'],
                                    'status' => $isManual ? 'COMPLETED' : 'PENDING',
                                    'payment_method' => $isManual ? 'MANUAL_CASH' : ($isCredit ? 'CREDITO' : 'LIBELULA'),
                                    'metadata' => [
                                        'rfid_tag' => $record->tag_code,
                                        'skip_wallet_update' => $isCredit || (!$record->is_virtual), // Skip wallet update if balance is already incremented
                                        'global_discount' => (float) ($data['global_discount'] ?? 0),
                                        'should_invoice' => (bool) $data['emit_invoice'],
                                    ],
                                ]);

                                // 3. Handle Invoicing if requested OR if we need a payment link
                                if ($data['emit_invoice'] || (!$isManual && !$isCredit)) {
                                    $service = app(\App\Services\LibelulaPaymentService::class);

                                    $productCode = \App\Models\Product::find($data['product_id'] ?? null)?->siat_product_code ?? '1';
                                    $lineItems = [
                                        [
                                            'concepto' => 'Recarga Billetera',
                                            'cantidad' => 1,
                                            'costo_unitario' => $amount,
                                            'descuento_unitario' => 0,
                                            'detalle' => $data['description'],
                                            'codigo_producto' => $productCode,
                                            'ignora_factura' => false,
                                        ]
                                    ];

                                    // if !$isManual, $isPaid is false so it creates a pending link and emits invoice LATER.
                                    // if $isManual, $isPaid is true so it emits invoice IMMEDIATELY.
                                    // For credit ($isCredit), we pass is_credit = true so Libelula generates invoice immediately without completion.
                                    $result = $service->createPayment($wallet, $amount, $data['description'], [
                                        'emite_factura' => $data['emit_invoice'],
                                        'internal_usage_tx' => true,
                                        'transaction_id' => $tx->id,
                                        'line_items' => $lineItems,
                                        'is_credit' => $isCredit,
                                    ], $isManual, (float) ($data['global_discount'] ?? 0)); // isPaid = $isManual, + discount
            
                                    if ($result['success']) {
                                        if ($isCredit) {
                                            Notification::make()
                                                ->title('Carga a Crédito Registrada')
                                                ->body('La tarjeta ha sido recargada y la factura fue emitida en Libélula.')
                                                ->success()
                                                ->send();
                                        } elseif (!$isManual && !empty($result['payment_url'])) {
                                            Notification::make()
                                                ->title('Link de Pago Generado')
                                                ->body('La transacción está pendiente. Usa el botón de Libélula para pagar.')
                                                ->success()
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->title('Recarga y Factura procesada')
                                                ->success()
                                                ->send();
                                        }
                                    } else {
                                        Notification::make()
                                            ->title('Error en Libélula')
                                            ->body($result['message'] ?? 'Error desconocido')
                                            ->warning()
                                            ->send();
                                    }
                                } else {
                                    if ($isCredit) {
                                        Notification::make()
                                            ->title('Carga a Crédito Registrada')
                                            ->body('La tarjeta ha sido recargada. Valide el pago en Transacciones cuando el cliente pague.')
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('Recarga exitosa')
                                            ->success()
                                            ->send();
                                    }
                                }
                            } else {
                                Notification::make()
                                    ->title('Recarga exitosa (Sin usuario)')
                                    ->success()
                                    ->send();
                            }
                        });
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    \pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction::make(),
                ]),
            ])
            ->headerActions([
                \pxlrbt\FilamentExcel\Actions\Tables\ExportAction::make()
                    ->label('Exportar Excel'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRfidTags::route('/'),
            'bulk' => BulkRfidManager::route('/bulk'),
            'edit' => Pages\EditRfidTag::route('/{record}/edit'),
        ];
    }
}

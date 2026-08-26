<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletResource\Pages;
use App\Filament\Resources\WalletResource\RelationManagers;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\SystemSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;
    protected static ?string $navigationIcon = 'heroicon-o-wallet';
    protected static ?string $navigationGroup = 'Finance';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Wallet Details')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->unique(ignoreRecord: true), // One wallet per user

                        Forms\Components\Select::make('currency')
                            ->options([
                                'BOB' => 'BOB (Boliviano)',
                                'USD' => 'USD (Dólar)',
                            ])
                            ->default('BOB')
                            ->required(),

                        Forms\Components\TextInput::make('balance')
                            ->label('Current Balance')
                            ->numeric()
                            ->default(0)
                            ->prefix('$')
                            ->disabled() // NEW: Disable direct editing
                            ->dehydrated(false), // Ensure it's not sent in save request
                    ])->columns(3),

                Forms\Components\Section::make('Credit Settings')
                    ->schema([
                        Forms\Components\Toggle::make('is_postpaid')
                            ->label('Enable Post-Paid (Credit)')
                            ->helperText('Allows balance to go negative up to the credit limit.')
                            ->live() // Reactive!
                            ->default(false),

                        Forms\Components\TextInput::make('credit_limit')
                            ->label('Credit Limit')
                            ->numeric()
                            ->default(0)
                            ->prefix('$')
                            ->visible(fn(Forms\Get $get) => $get('is_postpaid')) // Hide if not postpaid
                            ->required(fn(Forms\Get $get) => $get('is_postpaid')), // Required only if postpaid
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Correo')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('user.billing_document')
                    ->label('NIT / CI')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance')
                    ->money(fn($record) => $record->currency)
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('currency')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_postpaid')
                    ->boolean()
                    ->label('Post-Paid'),
                Tables\Columns\TextColumn::make('credit_limit')
                    ->money(fn($record) => $record->currency)
                    ->label('Limit'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('manual_recharge')
                    ->label('Manual Top-up')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Amount to Add')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->default(10)
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                $set('description', 'Manual Top-up - Bs ' . number_format((float) $state, 2));
                            }),
                        Forms\Components\TextInput::make('description')
                            ->label('Reason / Description')
                            ->placeholder('e.g. Promotion, Adjustment, Cash Deposit')
                            ->required()
                            ->maxLength(255)
                            ->default('Manual Top-up - Bs 10.00'),
                        Forms\Components\TextInput::make('global_discount')
                            ->label('Descuento Global (BOB)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Monto a descontar en el total de la factura oficial.'),
                        Forms\Components\Toggle::make('emit_invoice')
                            ->label('Emit Official Invoice (Libélula)')
                            ->default(fn() => SystemSetting::get()->invoicing_policy === 'recharge')
                            ->helperText('If enabled, a manual payment will be registered in Libélula to generate the SIAT invoice.'),
                    ])
                    ->action(function (Wallet $record, array $data) {
                        $amount = round((float) $data['amount'], 2);

                        DB::transaction(function () use ($record, $amount, $data) {
                            $record->balance = round(((float) $record->balance) + $amount, 2);
                            $record->save();

                            $refCol = Schema::hasColumn('wallet_transactions', 'reference_id') ? 'reference_id' : 'reference';

                            $insert = [
                                'wallet_id' => $record->id,
                                'user_id' => $record->user_id,
                                'type' => 'RECHARGE',
                                'amount' => $amount,
                                'balance_after' => $record->balance,
                                'currency' => $record->currency ?? 'BOB',
                                $refCol => 'MANUAL-' . now()->format('YmdHis'),
                                'description' => $data['description'], // Custom description per user request
                                'status' => 'COMPLETED',
                                'created_at' => now(),
                                'updated_at' => now(),
                                'metadata' => [
                                    'global_discount' => (float) ($data['global_discount'] ?? 0),
                                ],
                            ];

                            WalletTransaction::create($insert);
                        });

                        if ($data['emit_invoice'] ?? false) {
                            $service = new \App\Services\LibelulaPaymentService();
                            $service->createPayment($record, $amount, $data['description'], [
                                'emite_factura' => true,
                                'internal_usage_tx' => true, // We don't want a duplicate RECHARGE record
                            ], true, (float) ($data['global_discount'] ?? 0)); // isPaid = true, + discount
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Manual Top-up Successful')
                            ->body("Added BOB {$amount} to {$record->user->name}'s wallet.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('recharge')
                    ->label('Recharge (Libélula)')
                    ->icon('heroicon-o-credit-card')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Amount to Recharge')
                            ->numeric()
                            ->required()
                            ->prefix('BOB')
                            ->minValue(1)
                            ->default(100),
                        Forms\Components\TextInput::make('global_discount')
                            ->label('Descuento Global (BOB)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->action(function (Wallet $record, array $data) {
                        $service = new \App\Services\LibelulaPaymentService();
                        $result = $service->createPayment($record, $data['amount'], 'Recarga Wallet', [], false, (float) ($data['global_discount'] ?? 0));

                        if ($result['success']) {
                            // Redirect to Payment URL
                            // Filament Action typically stays on page, but we can open URL
                            \Filament\Notifications\Notification::make()
                                ->title('Payment Created')
                                ->body('Redirecting to Libélula...')
                                ->success()
                                ->send();

                            // Open URL in new tab using Javascript or redirect
                            // Since this is server side, we can return a redirect? 
                            // Filament actions can assume redirect returns.
                            return redirect()->away($result['payment_url']);
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Payment Failed')
                                ->body($result['error'] ?? 'Unknown error')
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWallets::route('/'),
            'create' => Pages\CreateWallet::route('/create'),
            'edit' => Pages\EditWallet::route('/{record}/edit'),
        ];
    }
}

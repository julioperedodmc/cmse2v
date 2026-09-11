<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Filament\Resources\WalletTransactionResource\RelationManagers;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Finanzas';
    protected static ?string $navigationLabel = 'Transacciones';
    protected static ?string $modelLabel = 'Transacción';
    protected static ?string $pluralModelLabel = 'Transacciones';

    public static function form(Form $form): Form
    {
        $isAdminOrAccountant = fn() => auth()->user()?->hasRole(['super_admin', 'system_accountant']);

        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->disabled(),
                Forms\Components\TextInput::make('type')
                    ->disabled(),
                Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->prefix('BOB')
                    ->disabled(!$isAdminOrAccountant()),
                Forms\Components\Select::make('status')
                    ->options([
                        'PENDING' => 'Pendiente',
                        'COMPLETED' => 'Completado',
                        'FAILED' => 'Fallido',
                    ])
                    ->disabled(!$isAdminOrAccountant()),
                Forms\Components\FileUpload::make('payment_evidence_path')
                    ->label('Evidencia de Pago')
                    ->image()
                    ->directory('payment-evidence')
                    ->visibility('private')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull()
                    ->disabled(!$isAdminOrAccountant()),
                Forms\Components\TextInput::make('external_payment_id')
                    ->label('Gateway ID')
                    ->disabled(),
                Forms\Components\TextInput::make('invoice_number')
                    ->label('Factura #')
                    ->disabled(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'wallet']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M H:i', 'America/La_Paz')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuario')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Correo')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('user.billing_document')
                    ->label('NIT/CI')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'RECHARGE' => 'success',
                        'CHARGE' => 'danger',
                        'REFUND' => 'warning',
                        'CREDIT' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn($record) => $record->currency)
                    ->sortable()
                    ->weight('bold')
                    ->color(fn($record) => $record->type === 'RECHARGE' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Balance')
                    ->money(fn($record) => $record->currency)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'COMPLETED' => 'success',
                        'PENDING' => 'warning',
                        'FAILED' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('description')
                    ->limit(20),
                Tables\Columns\TextColumn::make('payment_url')
                    ->label('Link')
                    ->limit(10)
                    ->copyable()
                    ->copyMessage('Link de pago copiado')
                    ->color('info'),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'CREDITO' => 'danger',
                        'MANUAL_CASH' => 'success',
                        'LIBELULA' => 'info',
                        default => 'gray',
                    })
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'RECHARGE' => 'Recarga (Recharge)',
                        'CHARGE' => 'Consumo/Cobro (Charge)',
                        'CREDIT' => 'Reembolso (Credit)',
                    ])
                    ->label('Tipo de Transacción'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'COMPLETED' => 'Completado',
                        'PENDING' => 'Pendiente',
                        'FAILED' => 'Fallido',
                    ])
                    ->label('Estado (Status)'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->label('Adjuntar Evidencia'),
                Tables\Actions\Action::make('validate_payment')
                    ->label('Validar Pago')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(
                        fn(WalletTransaction $record): bool =>
                        $record->status === 'PENDING' &&
                        $record->type === 'RECHARGE' &&
                        auth()->user()->hasRole(['super_admin', 'system_accountant', 'kiosko'])
                    )
                    ->action(function (WalletTransaction $record) {
                        $metadata = $record->metadata ?? [];
                        $shouldInvoice = $metadata['should_invoice'] ?? false;
                        
                        $libService = app(\App\Services\LibelulaPaymentService::class);

                        // If it has already been invoiced (has invoice_url), we don't invoice again!
                        if ($shouldInvoice && empty($record->invoice_url)) {
                            // Use the specific MANUAL method that Rafael requested
                            $result = $libService->createManualInvoice(
                                $record, 
                                $record->amount, 
                                $record->description ?: 'Recarga Manual Validada',
                                $metadata['line_items'] ?? null,
                                (float) ($metadata['global_discount'] ?? 0)
                            );

                            if ($result['success']) {
                                // Mark as completed in CMS
                                DB::transaction(function () use ($record) {
                                    $record->update([
                                        'status' => 'COMPLETED',
                                        'invoice_url' => $result['invoice_url'] ?? $record->invoice_url,
                                        'payment_method' => $record->payment_method === 'CREDITO' ? 'CREDITO' : 'CASH/MANUAL'
                                    ]);
                                });

                                Notification::make()
                                    ->title('Pago Validado y Factura Emitida')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Falla en Libélula')
                                    ->body($result['message'] ?? 'Error desconocido')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        } else {
                            // Regular manual completion without invoice (or if invoice was already emitted)
                            DB::transaction(function () use ($record, $metadata) {
                                $wallet = $record->wallet;
                                $skipUpdate = $metadata['skip_wallet_update'] ?? false;
                                
                                if (!$skipUpdate) {
                                    $wallet->increment('balance', $record->amount);
                                }

                                $record->update([
                                    'status' => 'COMPLETED',
                                    'balance_after' => $wallet->balance,
                                    'payment_method' => $record->payment_method === 'CREDITO' ? 'CREDITO' : 'CASH/MANUAL'
                                ]);
                            });

                            Notification::make()
                                ->title($shouldInvoice ? 'Pago Validado (Factura ya emitida)' : 'Pago Validado Correctamente')
                                ->success()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('check_status')
                    ->label('Verificar en Libélula')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn($record) => $record->status === 'PENDING' && !empty($record->payment_url))
                    ->action(function (WalletTransaction $record) {
                        $service = app(\App\Services\LibelulaPaymentService::class);
                        $success = $service->verifyStatus($record->id);
                        
                        if ($success) {
                            Notification::make()->title('Pago confirmado y wallet actualizada')->success()->send();
                        } else {
                            Notification::make()->title('El pago aún no ha sido procesado en Libélula')->info()->send();
                        }
                    }),
                Tables\Actions\Action::make('view_invoice')
                    ->label('Ver Factura')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->url(fn($record) => $record->invoice_url, true)
                    ->visible(fn($record) => !empty($record->invoice_url)),
                Tables\Actions\Action::make('copy_link')
                    ->label('Link de Pago')
                    ->icon('heroicon-o-link')
                    ->color('info')
                    ->url(fn($record) => $record->payment_url, true)
                    ->visible(fn($record) => !empty($record->payment_url) && $record->status === 'PENDING'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
            'index' => Pages\ListWalletTransactions::route('/'),
            'create' => Pages\CreateWalletTransaction::route('/create'),
            'edit' => Pages\EditWalletTransaction::route('/{record}/edit'),
        ];
    }
}

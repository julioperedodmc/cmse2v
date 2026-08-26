<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LibelulaApiLogResource\Pages;
use App\Models\LibelulaApiLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LibelulaApiLogResource extends Resource
{
    protected static ?string $model = LibelulaApiLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?string $navigationLabel = 'Logs Libélula';
    protected static ?string $modelLabel = 'Log de API Libélula';
    protected static ?string $pluralModelLabel = 'Logs de API Libélula';
    protected static ?int $navigationSort = 100;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Request')
                    ->schema([
                        Forms\Components\TextInput::make('endpoint')
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('method')
                            ->disabled(),
                        Forms\Components\TextInput::make('http_status')
                            ->label('HTTP Status')
                            ->disabled(),
                        Forms\Components\TextInput::make('transaction_id')
                            ->label('Transaction ID')
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label('Fecha y Hora')
                            ->timezone('America/La_Paz')
                            ->disabled(),
                    ])->columns(2),

                Forms\Components\Section::make('Payload & Response')
                    ->schema([
                        Forms\Components\Placeholder::make('request_payload')
                            ->label('Request Payload (Lo que enviamos)')
                            ->content(fn ($record) => new \Illuminate\Support\HtmlString('<pre style="background: #111827; color: #10b981; padding: 1rem; border-radius: 0.5rem; overflow-x: auto;">' . json_encode($record->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>'))
                            ->columnSpanFull(),
                            
                        Forms\Components\Placeholder::make('response_payload')
                            ->label('Response Payload (Lo que respondió Libélula)')
                            ->content(fn ($record) => new \Illuminate\Support\HtmlString('<pre style="background: #111827; color: #60a5fa; padding: 1rem; border-radius: 0.5rem; overflow-x: auto;">' . json_encode($record->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>'))
                            ->columnSpanFull(),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->timezone('America/La_Paz')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('method')
                    ->label('Método')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'POST' => 'primary',
                        'WEBHOOK' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('transaction_id')
                    ->label('Transacción')
                    ->searchable()
                    ->sortable()
                    ->url(fn (LibelulaApiLog $record): ?string => $record->transaction_id ? WalletTransactionResource::getUrl('edit', ['record' => $record->transaction_id]) : null)
                    ->color('info')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('transaction.user.name')
                    ->label('Usuario')
                    ->searchable()
                    ->sortable()
                    ->description(fn (LibelulaApiLog $record): ?string => $record->transaction?->user?->email),
                Tables\Columns\TextColumn::make('transaction.amount')
                    ->label('Monto')
                    ->money('BOB')
                    ->sortable()
                    ->color('success'),
                Tables\Columns\TextColumn::make('endpoint')
                    ->label('Endpoint')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('http_status')
                    ->label('HTTP Status')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400 && $state < 500 => 'warning',
                        $state >= 500 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver JSON'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListLibelulaApiLogs::route('/'),
            'view' => Pages\ViewLibelulaApiLog::route('/{record}'),
        ];
    }
}

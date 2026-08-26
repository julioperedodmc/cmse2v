<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChargingSessionResource\Pages;
use App\Filament\Resources\ChargingSessionResource\RelationManagers;
use App\Models\ChargingSession;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ChargingSessionResource extends Resource
{
    protected static ?string $model = ChargingSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?string $navigationLabel = 'Charging Sessions';
    protected static ?string $navigationGroup = 'Negocio';
    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('transaction_id')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Select::make('station_id')
                    ->relationship('station', 'name')
                    ->required(),
                Forms\Components\TextInput::make('connector_id')
                    ->required()
                    ->numeric(),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->default(null),
                Forms\Components\Select::make('rfid_tag_id')
                    ->relationship('rfidTag', 'name')
                    ->default(null),
                Forms\Components\TextInput::make('tariff_id')
                    ->numeric()
                    ->default(null),
                Forms\Components\DateTimePicker::make('start_time')
                    ->required(),
                Forms\Components\DateTimePicker::make('stop_time'),
                Forms\Components\TextInput::make('meter_start')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('meter_stop')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('total_energy_kwh')
                    ->required()
                    ->numeric()
                    ->default(0.0000),
                Forms\Components\TextInput::make('total_cost')
                    ->required()
                    ->numeric()
                    ->default(0.0000),
                Forms\Components\TextInput::make('currency')
                    ->required()
                    ->maxLength(3)
                    ->default('USD'),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('Active'),
                Forms\Components\TextInput::make('stop_reason')
                    ->maxLength(255)
                    ->default(null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('2s')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_id')
                    ->label('Tx ID')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('station.name')
                    ->label('Station Name')
                    ->description(fn(ChargingSession $record): string => $record->station->charge_box_id ?? '-')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('connector_id')
                    ->label('Gun #')
                    ->alignment('center')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rfidTag.tag_code')
                    ->label('RFID Tag')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Client')
                    ->description(fn(ChargingSession $record): string => $record->user->email ?? '')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'Active' => 'warning',
                        'Completed' => 'success',
                        'Faulted' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total_energy_kwh')
                    ->label('Energy')
                    ->suffix(' kWh')
                    ->numeric(2)
                    ->sortable(),

                // COST vs PRICE
                Tables\Columns\TextColumn::make('utility_cost')
                    ->label('Op. Cost')
                    ->money(fn($record) => $record->currency)
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Sale Price')
                    ->money(fn($record) => $record->currency)
                    ->weight('bold')
                    ->color('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('margin')
                    ->label('Profit')
                    ->money(fn($record) => $record->currency)
                    ->color(fn($state) => $state > 0 ? 'success' : 'danger')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Started')
                    ->dateTime('d M H:i', 'America/La_Paz')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('stop_time')
                    ->label('Stopped')
                    ->dateTime('d M H:i', 'America/La_Paz')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('start_time', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'Active' => 'Activo (Active)',
                        'Completed' => 'Completado (Completed)',
                        'Faulted' => 'Fallido (Faulted)',
                    ])
                    ->label('Estado de Sesión'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(), // Add View Action
                Tables\Actions\Action::make('invoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-document-text')
                    ->modalContent(fn(ChargingSession $record) => view('filament.pages.invoice-modal', ['record' => $record]))
                    ->modalSubmitAction(false) // View only
                    ->modalCancelActionLabel('Close'),
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
            // Reuse the Meter Values manager from Steve resource if possible, or create a shared one.
            // Since we defined the relationship in the CMS model, we can use the same manager class 
            // IF it doesn't have hardcoded assumptions about the parent record class.
            // Let's use the one we created: App\Filament\Resources\Steve\ChargingSessionResource\RelationManagers\MeterValuesRelationManager
            \App\Filament\Resources\Steve\ChargingSessionResource\RelationManagers\MeterValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChargingSessions::route('/'),
            'create' => Pages\CreateChargingSession::route('/create'),
            'edit' => Pages\EditChargingSession::route('/{record}/edit'),
            'view' => Pages\ViewChargingSession::route('/{record}'), // Enable View
        ];
    }
}

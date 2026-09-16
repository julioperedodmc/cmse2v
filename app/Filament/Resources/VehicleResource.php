<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Vehículos';

    protected static ?string $pluralLabel = 'Vehículos';

    protected static ?string $navigationGroup = 'Usuarios';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'staff_admin']) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Vehículo')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->label('Cliente / Propietario'),
                        Forms\Components\TextInput::make('brand')
                            ->label('Marca')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->label('Modelo')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('plate')
                            ->label('Placa')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Ej. 123ABC'),
                        Forms\Components\TextInput::make('vin')
                            ->label('VIN')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('battery_capacity')
                            ->label('Capacidad de Batería (kWh)')
                            ->numeric()
                            ->step(0.01),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Propietario')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('brand')
                    ->label('Marca')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('model')
                    ->label('Modelo')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plate')
                    ->label('Placa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vin')
                    ->label('VIN')
                    ->searchable(),
                Tables\Columns\TextColumn::make('battery_capacity')
                    ->label('Capacidad (kWh)')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado en')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageVehicles::route('/'),
        ];
    }
}

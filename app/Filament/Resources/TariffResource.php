<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TariffResource\Pages;
use App\Models\Tariff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TariffResource extends Resource
{
    protected static ?string $model = Tariff::class;
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Negocio';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('General Info')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Select::make('currency')
                                    ->options([
                                        'USD' => 'USD ($)',
                                        'BOB' => 'BOB (Bs)',
                                        'EUR' => 'EUR (€)',
                                    ])
                                    ->default('BOB')
                                    ->required(),
                                Forms\Components\Fieldset::make('Cargo por Inicio de Sesión / Conexión (Fee)')
                                    ->schema([
                                        Forms\Components\Toggle::make('is_parking_fee_enabled')
                                            ->label('¿Habilitar Fee de Inicio / Conexión?')
                                            ->default(true)
                                            ->helperText('Si se desactiva, no se cobrará el cargo inicial a ninguna modalidad.')
                                            ->live()
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('price_session')
                                            ->label('Monto del Fee')
                                            ->numeric()
                                            ->default(0)
                                            ->prefix(fn (Forms\Get $get) => $get('currency') === 'USD' ? '$' : 'Bs')
                                            ->columnSpanFull()
                                            ->visible(fn (Forms\Get $get) => (bool) $get('is_parking_fee_enabled')),

                                        Forms\Components\Fieldset::make('¿A quiénes se aplica el Fee?')
                                            ->schema([
                                                Forms\Components\Toggle::make('apply_fee_to_dealership_cards')
                                                    ->label('Concesionarias / Empresas')
                                                    ->helperText('Tarjetas RFID corporativas (SACI, Hansa, etc.)')
                                                    ->default(true),
                                                Forms\Components\Toggle::make('apply_fee_to_app')
                                                    ->label('App Móvil')
                                                    ->helperText('Usuarios que inician carga desde la app')
                                                    ->default(true),
                                                Forms\Components\Toggle::make('apply_fee_to_cards')
                                                    ->label('Tarjetas Particulares')
                                                    ->helperText('Tarjetas RFID B2C de clientes particulares')
                                                    ->default(false),
                                            ])
                                            ->columns(1)
                                            ->columnSpanFull()
                                            ->visible(fn (Forms\Get $get) => (bool) $get('is_parking_fee_enabled')),
                                    ])
                                    ->columnSpanFull(),

                                Forms\Components\Fieldset::make('Multa por Tiempo Excedido (Time Penalty)')
                                    ->schema([
                                        Forms\Components\Toggle::make('is_time_fee_enabled')
                                            ->label('¿Habilitar Multa por Tiempo Excedido?')
                                            ->default(true)
                                            ->helperText('Si se desactiva, no se aplicarán multas por tiempo a ninguna modalidad.')
                                            ->live()
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('free_minutes')
                                            ->label('Tiempo de Gracia (Minutos de Cortesía)')
                                            ->helperText('Minutos permitidos antes de comenzar a cobrar la multa por tiempo excedido.')
                                            ->numeric()
                                            ->default(0)
                                            ->suffix('min')
                                            ->columnSpanFull()
                                            ->visible(fn (Forms\Get $get) => (bool) $get('is_time_fee_enabled')),
                                    ])
                                    ->columnSpanFull(),

                                Forms\Components\Fieldset::make('Descuento Fijo por Sesión')
                                    ->schema([
                                        Forms\Components\TextInput::make('discount_fixed_amount')
                                            ->label('Monto del Descuento Fijo')
                                            ->numeric()
                                            ->default(0)
                                            ->prefix(fn (Forms\Get $get) => $get('currency') === 'USD' ? '$' : 'Bs')
                                            ->helperText('Monto a descontar del cargo inicial de la sesión.')
                                            ->live()
                                            ->columnSpanFull(),

                                        Forms\Components\Fieldset::make('¿A quiénes se aplica el Descuento?')
                                            ->schema([
                                                Forms\Components\Toggle::make('apply_discount_to_app')
                                                    ->label('App Móvil')
                                                    ->default(true),
                                                Forms\Components\Toggle::make('apply_discount_to_dealership_cards')
                                                    ->label('Concesionarias / Empresas')
                                                    ->default(false),
                                                Forms\Components\Toggle::make('apply_discount_to_cards')
                                                    ->label('Tarjetas Particulares')
                                                    ->default(false),
                                            ])
                                            ->columns(1)
                                            ->columnSpanFull()
                                            ->visible(fn (Forms\Get $get) => (float) $get('discount_fixed_amount') > 0),
                                    ])
                                    ->columnSpanFull(),

                                Forms\Components\Section::make('Notificación de Carga Alcanzada')
                                    ->description('Aviso automático al usuario cuando el vehículo llega a un nivel de batería específico.')
                                    ->schema([
                                        Forms\Components\Grid::make(2)
                                            ->schema([
                                                Forms\Components\TextInput::make('target_soc')
                                                    ->label('SOC Objetivo (%)')
                                                    ->numeric()
                                                    ->default(80)
                                                    ->suffix('%')
                                                    ->helperText('Nivel de batería para disparar la notificación.'),
                                                Forms\Components\Textarea::make('soc_reached_message')
                                                    ->label('Mensaje de Notificación')
                                                    ->placeholder('Tu vehículo ha llegado al {soc}% de carga. Tienes {minutes} minutos de cortesía para retirarlo sin cargos adicionales.')
                                                    ->helperText('Variables disponibles: {soc}, {minutes}')
                                                    ->columnSpanFull(),
                                            ])
                                    ]),

                                Forms\Components\DateTimePicker::make('valid_from')
                                    ->label('Tariff valid from')
                                    ->seconds(false)
                                    ->helperText('Optional. If empty, tariff is valid from the beginning.'),

                                Forms\Components\DateTimePicker::make('valid_until')
                                    ->label('Tariff valid until')
                                    ->seconds(false)
                                    ->after('valid_from')
                                    ->helperText('Optional. If empty, tariff has no end date.'),
                            ])->columns(2),

                        Forms\Components\Section::make('Vínculo con Productos SIAT')
                            ->description('Seleccione los productos oficiales que aparecerán en la factura para cada ítem de esta tarifa.')
                            ->schema([
                                Forms\Components\Select::make('energy_product_id')
                                    ->label('Producto de Energía')
                                    ->relationship('energyProduct', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Forms\Components\Select::make('connection_product_id')
                                    ->label('Producto Connection Fee')
                                    ->relationship('connectionProduct', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Forms\Components\Select::make('time_product_id')
                                    ->label('Producto Time Penalty')
                                    ->relationship('timeProduct', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                            ]),
                    ])->columnSpan(1),

                Forms\Components\Group::make()
                    ->schema([
                        // BLOCK 1 (Always Visible)
                        Forms\Components\Section::make('Time Block 1 (Default)')
                            ->description('Example: 00:00 - 23:59 (Full Day)')
                            ->schema([
                                Forms\Components\TimePicker::make('b1_start')->default('00:00')->required(),
                                Forms\Components\TimePicker::make('b1_end')->default('23:59')->required(),
                                
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('b1_price_kwh')
                                            ->label('Sell Price ($/kWh)')
                                            ->helperText('Price customer pays.')
                                            ->numeric()
                                            ->default(0),
                                        Forms\Components\TextInput::make('b1_cost_kwh')
                                            ->label('Buy Cost ($/kWh)')
                                            ->helperText('Your cost from utility.')
                                            ->numeric()
                                            ->default(0),
                                        Forms\Components\TextInput::make('b1_price_min')
                                            ->label('Time Fee ($/Min)')
                                            ->numeric()
                                            ->default(0),
                                    ]),
                            ])->columns(2),

                        // BLOCK 2
                        Forms\Components\Section::make('Time Block 2 (Optional)')
                            ->collapsed()
                            ->schema([
                                Forms\Components\TimePicker::make('b2_start'),
                                Forms\Components\TimePicker::make('b2_end'),
                                
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('b2_price_kwh')->label('Sell Price ($/kWh)')->numeric(),
                                        Forms\Components\TextInput::make('b2_cost_kwh')->label('Buy Cost ($/kWh)')->numeric(),
                                        Forms\Components\TextInput::make('b2_price_min')->label('Time Fee ($/Min)')->numeric(),
                                    ]),
                            ])->columns(2),

                        // BLOCK 3
                        Forms\Components\Section::make('Time Block 3 (Optional)')
                            ->collapsed()
                            ->schema([
                                Forms\Components\TimePicker::make('b3_start'),
                                Forms\Components\TimePicker::make('b3_end'),
                                
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('b3_price_kwh')->label('Sell Price ($/kWh)')->numeric(),
                                        Forms\Components\TextInput::make('b3_cost_kwh')->label('Buy Cost ($/kWh)')->numeric(),
                                        Forms\Components\TextInput::make('b3_price_min')->label('Time Fee ($/Min)')->numeric(),
                                    ]),
                            ])->columns(2),

                        // BLOCK 4
                        Forms\Components\Section::make('Time Block 4 (Optional)')
                            ->collapsed()
                            ->schema([
                                Forms\Components\TimePicker::make('b4_start'),
                                Forms\Components\TimePicker::make('b4_end'),

                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('b4_price_kwh')->label('Sell Price ($/kWh)')->numeric(),
                                        Forms\Components\TextInput::make('b4_cost_kwh')->label('Buy Cost ($/kWh)')->numeric(),
                                        Forms\Components\TextInput::make('b4_price_min')->label('Time Fee ($/Min)')->numeric(),
                                    ]),
                            ])->columns(2),
                    ])->columnSpan(2),
            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('currency')->sortable(),
                Tables\Columns\TextColumn::make('b1_price_kwh')->label('Base $/kWh')->money(),
                Tables\Columns\TextColumn::make('valid_from')->dateTime()->label('Valid from')->sortable(),
                Tables\Columns\TextColumn::make('valid_until')->dateTime()->label('Valid until')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Tariff $record) => !$record->hasBeenUsed() && !$record->isExpired()),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTariffs::route('/'),
            'create' => Pages\CreateTariff::route('/create'),
            'edit' => Pages\EditTariff::route('/{record}/edit'),
        ];
    }
}

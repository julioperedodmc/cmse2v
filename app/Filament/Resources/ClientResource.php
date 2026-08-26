<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ClientResource extends Resource
{
    protected static ?string $model = \App\Models\User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clientes App';

    protected static ?string $pluralLabel = 'Clientes App';

    protected static ?string $navigationGroup = 'Usuarios';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'staff_admin', 'sales', 'kiosko']) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->role('client');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Cliente')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel(),
                        Forms\Components\TextInput::make('password')
                            ->label('Nueva Contraseña (Dejar vacío para no cambiar)')
                            ->password()
                            ->dehydrated(fn ($state) => filled($state))
                            ->dehydrateStateUsing(fn ($state) => bcrypt($state))
                            ->maxLength(255),
                    ])->columns(2),
                Forms\Components\Section::make('Facturación')
                    ->schema([
                        Forms\Components\Select::make('billing_doc_type')
                            ->options(['NIT' => 'NIT', 'CI' => 'CI']),
                        Forms\Components\TextInput::make('billing_document')
                            ->label('NIT/CI')
                            ->unique(ignoreRecord: true),
                    ])->columns(3),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información del Cliente')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')->label('Nombre'),
                        Infolists\Components\TextEntry::make('email')->label('Email'),
                        Infolists\Components\TextEntry::make('phone')->label('Teléfono'),
                        Infolists\Components\TextEntry::make('created_at')->label('Fecha Registro')->dateTime(),
                    ])->columns(2),
                
                Infolists\Components\Section::make('Facturación')
                    ->schema([
                        Infolists\Components\TextEntry::make('billing_doc_type')->label('Tipo Doc'),
                        Infolists\Components\TextEntry::make('billing_document')->label('Documento'),
                        Infolists\Components\TextEntry::make('billing_razon_social')->label('Razón Social'),
                    ])->columns(3),

                Infolists\Components\Section::make('Tarjetas / Identificación')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('rfidTags')
                            ->label('Tarjetas Vinculadas')
                            ->schema([
                                Infolists\Components\TextEntry::make('tag_code')
                                    ->label('Código Tag')
                                    ->weight('bold'),
                                Infolists\Components\TextEntry::make('is_virtual')
                                    ->label('Tipo')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => $state ? 'Virtual' : 'Física')
                                    ->color(fn ($state) => $state ? 'info' : 'gray'),
                                Infolists\Components\IconEntry::make('is_active')
                                    ->label('Activa')
                                    ->boolean(),
                                Infolists\Components\TextEntry::make('balance')
                                    ->label('Saldo Tarjeta')
                                    ->money('BOB'),
                            ])->columns(4)
                    ]),

                Infolists\Components\Section::make('Vehículos Registrados')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('vehicles')
                            ->label('Vehículos del Cliente')
                            ->schema([
                                Infolists\Components\TextEntry::make('brand')
                                    ->label('Marca')
                                    ->weight('bold'),
                                Infolists\Components\TextEntry::make('model')
                                    ->label('Modelo'),
                                Infolists\Components\TextEntry::make('plate')
                                    ->label('Placa')
                                    ->badge()
                                    ->color('primary'),
                                Infolists\Components\TextEntry::make('vin')
                                    ->label('VIN/VID'),
                                Infolists\Components\TextEntry::make('battery_capacity')
                                    ->label('Capacidad Batería')
                                    ->suffix(' kWh'),
                            ])->columns(5)
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono'),
                Tables\Columns\TextColumn::make('billing_document')
                    ->label('Documento'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado el')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('assign_virtual_tag')
                    ->label('Asignar Tag Virtual')
                    ->icon('heroicon-o-identification')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (\App\Models\User $record) => !$record->rfidTags()->where('is_virtual', true)->exists())
                    ->action(function (\App\Models\User $record) {
                        $tagCode = 'A' . strtoupper(\Illuminate\Support\Str::random(7));
                        while (\App\Models\RfidTag::where('tag_code', $tagCode)->exists()) {
                            $tagCode = 'A' . strtoupper(\Illuminate\Support\Str::random(7));
                        }

                        $virtualProduct = \App\Models\Product::where('internal_code', 'VIRTUAL-TAG')->first();

                        \App\Models\RfidTag::create([
                            'tag_code' => $tagCode,
                            'user_id' => $record->id,
                            'product_id' => $virtualProduct?->id,
                            'name' => 'Tag Virtual App',
                            'is_active' => true,
                            'is_virtual' => true,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->title('Tag Virtual asignado')
                            ->body("Se ha generado el código: $tagCode")
                            ->success()
                            ->send();
                    }),
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
            'index' => Pages\ManageClients::route('/'),
        ];
    }
}

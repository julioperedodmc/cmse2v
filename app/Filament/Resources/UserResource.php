<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Usuarios Administrativos';

    protected static ?string $pluralLabel = 'Usuarios Administrativos';

    protected static ?string $navigationGroup = 'Usuarios';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'staff_admin']) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereDoesntHave('roles', function ($query) {
            $query->where('name', 'client');
        });

        // Hide super_admin from non-super_admin users
        if (!auth()->user()?->hasRole('super_admin')) {
            $query->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'super_admin');
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de Usuario')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre Completo')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Correo Electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                        Forms\Components\Select::make('roles')
                            ->label('Roles / Tipo de Usuario')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('company_id')
                            ->label('Empresa (Solo para Enterprise)')
                            ->relationship('company', 'name')
                            ->searchable()
                            ->preload(),
                    ])->columns(2),

                Forms\Components\Section::make('Detalles de Facturación / Contacto')
                    ->schema([
                        Forms\Components\TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel(),
                        Forms\Components\TextInput::make('billing_document')
                            ->label('Documento/NIT')
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('billing_doc_type')
                            ->label('Tipo de Documento')
                            ->options([
                                'NIT' => 'NIT',
                                'CI' => 'CI',
                                'CUE' => 'CUE',
                            ]),
                        Forms\Components\TextInput::make('billing_razon_social')
                            ->label('Razón Social'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sap_client_code')
                    ->label('Código SAP')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label('Correo Verificado')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado en')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado en')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('company.name')
                    ->label('Empresa')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address')
                    ->label('Dirección')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_document')
                    ->label('Documento/NIT')
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_doc_type')
                    ->label('Tipo Doc.')
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_complement')
                    ->label('Complemento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_razon_social')
                    ->label('Razón Social')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Es Admin')
                    ->boolean(),
                Tables\Columns\TextColumn::make('sap_synced_at')
                    ->label('Sincronizado SAP')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('revoke_sessions')
                    ->label('Cerrar Sesión')
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (\App\Models\User $record) => "Cerrar sesiones de {$record->name}")
                    ->modalDescription('¿Estás seguro de que deseas revocar todas las sesiones activas de este usuario?')
                    ->modalSubmitActionLabel('Sí, cerrar sesiones')
                    ->action(function (\App\Models\User $record) {
                        $count = $record->tokens()->count();
                        $record->tokens()->delete();
                        $record->update(['fcm_token' => null]);

                        \Filament\Notifications\Notification::make()
                            ->title('Sesiones revocadas')
                            ->body("Se revocaron las sesiones de {$record->name} ({$count} token(s) eliminados).")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUsers::route('/'),
        ];
    }
}

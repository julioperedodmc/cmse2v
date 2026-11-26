<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SteveUserResource\Pages;
use App\Models\Steve\WebUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SteveUserResource extends Resource
{
    protected static ?string $model = WebUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?string $navigationLabel = 'Usuarios SteVe (OCPP)';

    protected static ?string $modelLabel = 'Usuario SteVe';

    protected static ?string $pluralModelLabel = 'Usuarios SteVe (OCPP)';

    protected static ?int $navigationSort = 105;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        // Proteger a los usuarios iniciales del sistema (e2vadm y mgr_api)
        return (auth()->user()?->hasRole('super_admin') ?? false) && ((int) $record->web_user_pk > 2);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Usuario SteVe')
                    ->description('Defina la identidad, estado de activación y roles para este usuario en el servidor SteVe.')
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->label('Nombre de Usuario')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true)
                            ->helperText('Identificador único para iniciar sesión en SteVe.'),

                        Forms\Components\Toggle::make('enabled')
                            ->label('Estado: Usuario Habilitado')
                            ->helperText('Permitir o denegar el acceso a SteVe para esta cuenta.')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger')
                            ->disabled(fn(?WebUser $record): bool => $record?->web_user_pk === 1),

                        Forms\Components\CheckboxList::make('authorities')
                            ->label('Roles y Permisos')
                            ->options([
                                'ROLE_ADMIN' => 'Administrador Web (ROLE_ADMIN)',
                                'ROLE_MANAGER' => 'Manager de Operaciones (ROLE_MANAGER)',
                                'ROLE_API' => 'Acceso a la API REST (ROLE_API)',
                            ])
                            ->descriptions([
                                'ROLE_ADMIN' => 'Permite acceso total al panel administrativo web de SteVe.',
                                'ROLE_MANAGER' => 'Permite supervisar puntos de carga y operaciones activas.',
                                'ROLE_API' => 'Permite autenticación HTTP para consumo de endpoints REST.',
                            ])
                            ->default(['ROLE_ADMIN'])
                            ->required()
                            ->afterStateHydrated(function ($component, $state) {
                                if (is_array($state)) {
                                    $component->state(array_values(array_intersect($state, ['ROLE_ADMIN', 'ROLE_MANAGER', 'ROLE_API'])));
                                }
                            })
                            ->dehydrateStateUsing(function ($state) {
                                $roles = is_array($state) ? $state : [];
                                if (in_array('ROLE_ADMIN', $roles) && !in_array('ADMIN', $roles)) {
                                    $roles[] = 'ADMIN';
                                }
                                return array_values(array_unique($roles));
                            }),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Seguridad de la Cuenta')
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->label('Contraseña de Acceso')
                            ->password()
                            ->revealable()
                            ->required(fn(string $operation): bool => $operation === 'create')
                            ->minLength(8)
                            ->same('password_confirmation')
                            ->dehydrated(fn($state) => filled($state))
                            ->dehydrateStateUsing(fn($state) => password_hash($state, PASSWORD_BCRYPT))
                            ->helperText(fn(string $operation): string => $operation === 'create'
                                ? 'Mínimo 8 caracteres. Se cifrará de manera segura con BCrypt.'
                                : 'Dejar en blanco si no desea modificar la contraseña actual.'),

                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirmar Contraseña')
                            ->password()
                            ->revealable()
                            ->required(fn(string $operation): bool => $operation === 'create')
                            ->dehydrated(false)
                            ->helperText('Vuelva a escribir la contraseña para confirmar que coincida.'),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('web_user_pk')
                    ->label('ID')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('username')
                    ->label('Usuario SteVe')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->icon('heroicon-o-user'),

                Tables\Columns\IconColumn::make('enabled')
                    ->label('Estado')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('formatted_roles')
                    ->label('Roles Asignados')
                    ->badge()
                    ->state(function (WebUser $record): array {
                        $roles = is_array($record->authorities) ? $record->authorities : [];
                        $display = [];
                        if (in_array('ROLE_ADMIN', $roles) || in_array('ADMIN', $roles)) {
                            $display[] = 'Admin Web';
                        }
                        if (in_array('ROLE_MANAGER', $roles)) {
                            $display[] = 'Manager';
                        }
                        if (in_array('ROLE_API', $roles)) {
                            $display[] = 'API REST';
                        }
                        return empty($display) ? ['Sin Rol'] : $display;
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'Admin Web' => 'success',
                        'Manager' => 'warning',
                        'API REST' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Filtrar por Estado')
                    ->trueLabel('Solo Habilitados')
                    ->falseLabel('Solo Deshabilitados'),
            ])
            ->actions([
                Tables\Actions\Action::make('change_password')
                    ->label('Cambiar Contraseña')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->button()
                    ->modalHeading(fn(WebUser $record) => "Cambiar Contraseña: {$record->username}")
                    ->modalDescription('Actualice la contraseña de acceso al panel o API de SteVe. Se aplicará cifrado BCrypt con efecto inmediato.')
                    ->modalIcon('heroicon-o-lock-closed')
                    ->modalSubmitActionLabel('Actualizar Contraseña')
                    ->form([
                        Forms\Components\TextInput::make('new_password')
                            ->label('Nueva Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->same('new_password_confirmation')
                            ->helperText('Mínimo 8 caracteres.'),

                        Forms\Components\TextInput::make('new_password_confirmation')
                            ->label('Confirmar Nueva Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText('Vuelva a escribir la contraseña para confirmar que coincida.'),
                    ])
                    ->action(function (WebUser $record, array $data): void {
                        $hash = password_hash($data['new_password'], PASSWORD_BCRYPT);
                        $record->password = $hash;
                        $record->save();

                        // Auditoría en log del sistema
                        $adminEmail = auth()->user()?->email ?? 'desconocido';
                        Log::info("AUDITORIA_SEGURIDAD: El administrador [{$adminEmail}] actualizó la contraseña del usuario SteVe ID {$record->web_user_pk} ({$record->username}).");

                        if (function_exists('activity')) {
                            try {
                                activity('steve_security')
                                    ->causedBy(auth()->user())
                                    ->performedOn($record)
                                    ->log("Cambio de contraseña para usuario SteVe '{$record->username}'");
                            } catch (\Throwable $t) {
                                // Fallback seguro
                            }
                        }

                        Notification::make()
                            ->title('Contraseña actualizada con éxito')
                            ->body("La nueva contraseña para '{$record->username}' ya está activa en el servidor SteVe.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->modalHeading(fn(WebUser $record) => "Editar Usuario SteVe: {$record->username}")
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['password'] = null;
                        return $data;
                    })
                    ->using(function (Model $record, array $data): Model {
                        if (!empty($data['password'])) {
                            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
                        } else {
                            unset($data['password']);
                        }
                        unset($data['password_confirmation']);

                        $record->update($data);

                        $adminEmail = auth()->user()?->email ?? 'desconocido';
                        Log::info("AUDITORIA_SEGURIDAD: El administrador [{$adminEmail}] editó al usuario SteVe ID {$record->web_user_pk} ({$record->username}).");

                        return $record;
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar')
                    ->modalHeading(fn(WebUser $record) => "Eliminar Usuario SteVe: {$record->username}")
                    ->modalDescription('¿Está seguro de que desea eliminar este usuario adicional de SteVe?')
                    ->visible(fn(WebUser $record): bool => (int) $record->web_user_pk > 2),
            ])
            ->bulkActions([
                // No permitir eliminación masiva para proteger cuentas de SteVe
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSteveUsers::route('/'),
        ];
    }
}

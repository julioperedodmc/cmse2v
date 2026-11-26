<?php

namespace App\Filament\Resources\SteveUserResource\Pages;

use App\Filament\Resources\SteveUserResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageSteveUsers extends ManageRecords
{
    protected static string $resource = SteveUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nuevo Usuario SteVe')
                ->icon('heroicon-o-plus')
                ->modalHeading('Crear Nuevo Usuario SteVe')
                ->modalDescription('Cree un nuevo usuario con permisos de acceso al servidor OCPP SteVe.')
                ->modalSubmitActionLabel('Crear Usuario')
                ->successNotificationTitle('Usuario SteVe creado exitosamente'),
        ];
    }
}

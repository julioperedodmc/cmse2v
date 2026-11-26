<?php

namespace App\Filament\Pages;

use App\Models\SystemSetting;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Actions\Action;
use App\Models\Wallet;
use App\Services\LibelulaPaymentService;

class ManageSettings extends Page
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Configuración General';
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?int $navigationSort = 100;
    protected static ?string $title = 'Configuración de la Plataforma';

    protected static string $view = 'filament.pages.manage-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(SystemSetting::get()->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Identidad de la Plataforma')
                    ->description('Configure los datos básicos del sistema.')
                    ->schema([
                        TextInput::make('platform_name')
                            ->label('Nombre de la Plataforma')
                            ->required(),
                        FileUpload::make('logo_path')
                            ->label('Logo de la Plataforma')
                            ->image()
                            ->directory('platform')
                            ->visibility('public'),
                    ]),

                Section::make('Aviso Legal (Disclaimer)')
                    ->description('Configuración del texto legal que verán los usuarios.')
                    ->schema([
                        Textarea::make('disclaimer_text')
                            ->label('Texto del Disclaimer')
                            ->rows(5),
                        Toggle::make('is_disclaimer_visible')
                            ->label('¿Mostrar Disclaimer en App?')
                            ->helperText('Habilita o deshabilita la visualización del disclaimer en los enlaces públicos.'),
                        Actions::make([
                            FormAction::make('view_disclaimer')
                                ->label('Ver Disclaimer Público')
                                ->icon('heroicon-o-eye')
                                ->url(url('/disclaimer'), true)
                                ->color('info')
                                ->button(),
                        ]),
                    ]),

                Section::make('Estética de la Aplicación Móvil')
                    ->description('Personalice los colores y la tipografía de la app.')
                    ->schema([
                        ColorPicker::make('primary_color')
                            ->label('Color Primario'),
                        ColorPicker::make('secondary_color')
                            ->label('Color Secundario'),
                        ColorPicker::make('button_color')
                            ->label('Color de Botones'),
                        ColorPicker::make('text_color')
                            ->label('Color de Texto Principal'),
                        Select::make('font_family')
                            ->label('Tipografía (Google Fonts)')
                            ->options([
                                'Inter' => 'Inter',
                                'Roboto' => 'Roboto',
                                'Open Sans' => 'Open Sans',
                                'Montserrat' => 'Montserrat',
                                'Poppins' => 'Poppins',
                                'Outfit' => 'Outfit',
                            ])
                            ->searchable(),
                    ])->columns(2),
                
                Section::make('Pasarela de Pagos (Libélula)')
                    ->description('Administre las credenciales de la pasarela de pagos.')
                    ->schema([
                        TextInput::make('libelula_app_key')
                            ->label('Libelula App Key')
                            ->password()
                            ->revealable()
                            ->helperText('Pegue aquí la clave proporcionada por Libélula.'),
                        TextInput::make('libelula_api_url')
                            ->label('URL de la API')
                            ->placeholder('https://api.libelula.bo/rest')
                            ->helperText('Por defecto: https://api.libelula.bo/rest'),
                    ])->columns(2),

                Section::make('Configuración Caja Manual (Facturación Directa)')
                    ->description('Credenciales específicas para el canal de caja física y facturación inmediata.')
                    ->schema([
                        TextInput::make('libelula_invoicing_app_key')
                            ->label('Libelula Invoicing App Key')
                            ->password()
                            ->revealable()
                            ->helperText('App Key dedicada exclusivamente para la emisión de facturas directas.'),
                        TextInput::make('libelula_canal_caja')
                            ->label('Hash Canal Caja')
                            ->password()
                            ->revealable()
                            ->helperText('Hash proporcionado por Libélula para cobros directos.'),
                        TextInput::make('libelula_canal_caja_sucursal')
                            ->label('Nombre de Sucursal')
                            ->placeholder('SUCURSAL 1'),
                        TextInput::make('libelula_canal_caja_usuario')
                            ->label('Nombre de Usuario/Cajero')
                            ->placeholder('CAJERO 1'),
                        TextInput::make('libelula_sector_code')
                            ->label('Código de Sector (SIAT)')
                            ->default('1')
                            ->helperText('Generalmente 1 para servicios estándar.'),
                        TextInput::make('libelula_product_code')
                            ->label('Código de Producto (SIAT)')
                            ->default('1')
                            ->helperText('Código de producto registrado en su catálogo de Libélula (ej: 1 o 100).'),
                    ])->columns(2),

                Section::make('Políticas de Negocio y Facturación (SIAT)')
                    ->description('Defina cómo y cuándo se generan los documentos tributarios.')
                    ->schema([
                        Select::make('invoicing_policy')
                            ->label('Momento de Facturacion')
                            ->options([
                                'recharge' => 'Al Recargar Saldo (Wallet Topup)',
                                'usage' => 'Al Consumir Energía (Final de Carga)',
                            ])
                            ->required()
                            ->helperText('Define si Libélula emite la factura en el pago o al finalizar la carga de energía.'),
                        
                        Select::make('nit_requirement_policy')
                            ->label('Política de Acceso a la App')
                            ->options([
                                'optional' => 'Opcional (Permitir uso sin NIT/CI)',
                                'required' => 'Obligatorio (Exigir NIT/CI para usar la App)',
                            ])
                            ->required()
                            ->helperText('Si es obligatorio, los usuarios sin NIT/CI verán un bloqueo en la App hasta registrar sus datos.'),

                        Toggle::make('invoice_on_bulk_creation')
                            ->label('Facturar automáticamente en creación masiva')
                            ->default(true)
                            ->helperText('Si está activado, se emitirá una factura por el saldo inicial al crear tarjetas en lote.'),

                        TextInput::make('billing_grace_period')
                            ->label('Periodo de Gracia (Minutos)')
                            ->numeric()
                            ->default(3)
                            ->suffix('minutos')
                            ->helperText('Tiempo en el que no se volverá a cobrar el fee de inicio si un usuario reinicia una carga.'),

                        Toggle::make('waive_parking_fee_for_cards')
                            ->label('Exonerar Fee a Tarjetas Particulares')
                            ->default(false)
                            ->helperText('Si se activa, a los usuarios con tarjeta particular no se les cobrará el cargo por conexión.'),
                        Toggle::make('waive_parking_fee_for_dealerships')
                            ->label('Exonerar Fee a Concesionarias/Empresas')
                            ->default(false)
                            ->helperText('Si se activa, a las tarjetas asociadas a concesionarias o flotas empresariales no se les cobrará el cargo por conexión.'),
                        Toggle::make('restrict_charging_without_vehicle')
                            ->label('Restringir carga sin vehículo registrado')
                            ->default(false)
                            ->helperText('Si se activa, los usuarios de la App móvil no podrán iniciar una carga a menos que tengan al menos un vehículo registrado y seleccionen uno.'),
                    ])->columns(2),

                Section::make('Configuración de Correo Electrónico (SMTP)')
                    ->description('Administre los parámetros del servidor de correo para notificaciones y restablecimiento de contraseñas.')
                    ->schema([
                        TextInput::make('mail_host')
                            ->label('Servidor SMTP (Host)')
                            ->placeholder('ej. mail.dmc.com.bo'),
                        TextInput::make('mail_port')
                            ->label('Puerto SMTP')
                            ->numeric()
                            ->default(587),
                        Select::make('mail_encryption')
                            ->label('Seguridad')
                            ->options([
                                'tls' => 'TLS / STARTTLS',
                                'ssl' => 'SSL',
                                'none' => 'Ninguna',
                            ]),
                        TextInput::make('mail_username')
                            ->label('Usuario de Autenticación')
                            ->placeholder('ej. infoep@dmc.com.bo'),
                        TextInput::make('mail_password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable(),
                        TextInput::make('mail_from_address')
                            ->label('Correo Remitente (From Address)')
                            ->placeholder('ej. info@electropoint.bo'),
                        TextInput::make('mail_from_name')
                            ->label('Nombre del Remitente (From Name)')
                            ->placeholder('ej. Electropoint'),
                    ])->columns(2),

                Section::make('Mapeo de Productos para Facturación (SFE)')
                    ->description('Asigne qué productos de su catálogo se usarán para cada concepto en la factura.')
                    ->schema([
                        Select::make('product_recharge_id')
                            ->label('Producto para Recargas')
                            ->options(\App\Models\Product::pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Usado al realizar recargas manuales o por QR.'),
                        
                        Select::make('product_energy_id')
                            ->label('Producto para Energía (kWh)')
                            ->options(\App\Models\Product::pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Usado para el concepto de consumo de energía.'),

                        Select::make('product_connection_id')
                            ->label('Producto para Cargo de Inicio (Fee)')
                            ->options(\App\Models\Product::pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Usado para el concepto de cargo por conexión/inicio.'),

                        Select::make('product_penalty_id')
                            ->label('Producto para Multa por Tiempo')
                            ->options(\App\Models\Product::pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Usado para el concepto de penalty fee por tiempo excedido.'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar Cambios')
                ->submit('save'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_libelula')
                ->label('Probar Libélula')
                ->icon('heroicon-o-bug-ant')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Diagnóstico de Conexión Libélula')
                ->modalDescription('Se enviará una solicitud de cobro de Bs 1 para verificar que la configuración sea correcta. No se generará ningún cargo real.')
                ->action(fn () => $this->runLibelulaTest()),
        ];
    }

    public function runLibelulaTest(): void
    {
        $wallet = Wallet::first();
        
        if (!$wallet) {
            Notification::make()
                ->title('Error de prueba')
                ->body('No hay billeteras de usuario en el sistema para realizar el test.')
                ->danger()
                ->send();
            return;
        }

        // Usamos el servicio pero inyectando los datos actuales del formulario (aunque no se hayan guardado)
        // O simplemente los guardados si el usuario ya los salvó.
        $service = app(LibelulaPaymentService::class);
        $result = $service->createPayment($wallet, 1.00, 'Test de Diagnóstico CMS');

        if ($result['success']) {
            Notification::make()
                ->title('Conexión Exitosa')
                ->body('Libélula respondió correctamente. URL generada: ' . $result['payment_url'])
                ->success()
                ->persistent()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Ver URL')
                        ->url($result['payment_url'], true),
                ])
                ->send();
        } else {
            Notification::make()
                ->title('Falla en la Conexión')
                ->body('Detalle: ' . ($result['detail'] ?? 'Error desconocido'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function save(): void
    {
        $settings = SystemSetting::get();
        $settings->update($this->form->getState());

        Notification::make()
            ->title('Configuración guardada correctamente')
            ->success()
            ->send();
    }
}

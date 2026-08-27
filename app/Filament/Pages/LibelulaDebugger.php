<?php

namespace App\Filament\Pages;

use App\Services\LibelulaPaymentService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class LibelulaDebugger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?string $title = 'Libelula Debugger';
    protected static ?string $navigationLabel = 'Debugger Libélula';
    protected static string $view = 'filament.pages.libelula-debugger';

    public ?array $data = [];
    public ?array $lastResponse = null;
    public ?array $lastRequest = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'email_cliente' => auth()->user()->email,
            'nombre_cliente' => auth()->user()->name,
            'razon_social' => 'Usuario de Prueba',
            'numero_documento' => '1234567',
            'placa_vehiculo' => '1111ABC',
            'codigo_producto' => '1',
            'concepto' => 'Factura de prueba Sector 31',
            'monto' => 10,
            'descuento' => 0,
            'descuento_global' => 0,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos del Cliente')
                    ->schema([
                        TextInput::make('email_cliente')->required()->email(),
                        TextInput::make('nombre_cliente')->required(),
                        TextInput::make('razon_social')->required(),
                        TextInput::make('numero_documento')->required()->label('NIT / CI'),
                        TextInput::make('placa_vehiculo')
                            ->required()
                            ->default('1111ABC')
                            ->label('Placa del Vehículo (Metadato Sector 31)'),
                    ])->columns(2),

                Section::make('Detalle del Ítem a Facturar')
                    ->schema([
                        TextInput::make('codigo_producto')
                            ->label('Código Producto SIAT')
                            ->required()
                            ->default('1'),
                        TextInput::make('concepto')
                            ->required()
                            ->label('Concepto / Detalle'),
                        TextInput::make('monto')
                            ->numeric()
                            ->required()
                            ->label('Monto Unitario (Bs)'),
                        TextInput::make('descuento')
                            ->numeric()
                            ->required()
                            ->label('Descuento Unitario (Bs)'),
                        TextInput::make('descuento_global')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->label('Descuento Global (Bs)'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $service = app(LibelulaPaymentService::class);

        $result = $service->testInvoiceRequest($data);

        $this->lastRequest = $result['request_payload'] ?? [];
        $this->lastResponse = [
            'http_status' => $result['http_status'] ?? 500,
            'body' => $result['response_body'] ?? []
        ];

        if (($result['http_status'] ?? 0) === 200 && empty($result['response_body']['data']['error'])) {
            Notification::make()->title('Prueba Exitosa')->success()->send();
        } else {
            Notification::make()->title('Error o Timeout en Libélula')->danger()->send();
        }
    }
}

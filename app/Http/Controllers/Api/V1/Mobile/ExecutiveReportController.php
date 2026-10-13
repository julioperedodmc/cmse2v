<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Mobile\Reports\AetnBillingReportController;
use App\Http\Controllers\Api\V1\Mobile\Reports\ClientBalancesReportController;
use App\Http\Controllers\Api\V1\Mobile\Reports\ExecutiveSummaryReportController;
use App\Http\Controllers\Api\V1\Mobile\Reports\OperationsReportController;
use App\Http\Controllers\Api\V1\Mobile\Reports\RfidDealershipsReportController;
use App\Http\Controllers\Api\V1\Mobile\Reports\VehicleReportController;
use App\Http\Controllers\Api\V1\Mobile\Reports\Traits\HasReportFilters;
use Illuminate\Http\Request;

/**
 * ExecutiveReportController
 * 
 * Controlador puente/fachada que delega cada reporte a su respectivo controlador especializado
 * ubicado en el namespace App\Http\Controllers\Api\V1\Mobile\Reports\
 */
class ExecutiveReportController extends Controller
{
    use HasReportFilters;

    /**
     * Endpoint: Resumen Ejecutivo Principal
     */
    public function getExecutiveSummary(Request $request)
    {
        return app(ExecutiveSummaryReportController::class)->getExecutiveSummary($request);
    }

    /**
     * Endpoint: Facturación y Energía AETN (Bloques Tarifarios)
     */
    public function getAetnBilling(Request $request)
    {
        return app(AetnBillingReportController::class)->getAetnBilling($request);
    }

    /**
     * Endpoint: Operación de Cargas (Fallas, Horarios, Intentos)
     */
    public function getOperations(Request $request)
    {
        return app(OperationsReportController::class)->getOperations($request);
    }

    /**
     * Endpoint: Clientes y Saldos (Recargas vs Consumos)
     */
    public function getClientBalances(Request $request)
    {
        return app(ClientBalancesReportController::class)->getClientBalances($request);
    }

    /**
     * Endpoint: RFID / Concesionarias (JAC, XPENG, CHANGAN, MG)
     */
    public function getRfidDealerships(Request $request)
    {
        return app(RfidDealershipsReportController::class)->getRfidDealerships($request);
    }

    /**
     * Endpoint: Vehículos y Análisis de Brecha
     */
    public function getVehicleReports(Request $request)
    {
        return app(VehicleReportController::class)->getVehicleReports($request);
    }
}

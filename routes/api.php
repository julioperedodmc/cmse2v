<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\AuthController;
use App\Http\Controllers\Api\V1\Mobile\StationController;
use App\Http\Controllers\Api\V1\Mobile\ChargingSessionController;
use App\Http\Controllers\Api\V1\Mobile\WalletController;
use App\Http\Controllers\Api\V1\Mobile\NotificationController;
use App\Http\Controllers\Api\V1\Mobile\LocationController;
use App\Http\Controllers\Api\V1\Mobile\VehicleController;
use App\Http\Controllers\Api\V1\Mobile\DashboardReportController;
use App\Http\Controllers\Api\V1\Mobile\ExecutiveReportController;
use App\Http\Controllers\Api\V1\Sap\SapExportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| MOBILE APP API (V1) - MODULAR STRUCTURE
|--------------------------------------------------------------------------
*/
Route::prefix('v1/mobile')->group(function () {
    // 0. AUTH & SYSTEM (Public)
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/validate-field', [AuthController::class, 'validateField']);
    Route::post('/google-login', [AuthController::class, 'googleLogin']);
    Route::post('/password/email', [AuthController::class, 'sendResetPin']);
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    Route::get('/config', [App\Http\Controllers\Api\V1\Mobile\SystemController::class, 'config']);
    Route::post('/config/seen', [App\Http\Controllers\Api\V1\Mobile\SystemController::class, 'trackSeen']);
    Route::get('/cards/lookup', [App\Http\Controllers\Api\V1\Mobile\RfidTagController::class, 'lookup']);

    // Executive & Operational Reports API Suite
    Route::prefix('reports')->group(function () {
        Route::get('/filter-options', [ExecutiveReportController::class, 'getFilterOptions']);
        Route::get('/dashboard-summary', [DashboardReportController::class, 'getSummary']);
        Route::get('/executive-summary', [ExecutiveReportController::class, 'getExecutiveSummary']);
        Route::get('/aetn-billing', [ExecutiveReportController::class, 'getAetnBilling']);
        Route::get('/operations', [ExecutiveReportController::class, 'getOperations']);
        Route::get('/client-balances', [ExecutiveReportController::class, 'getClientBalances']);
        Route::get('/rfid-dealerships', [ExecutiveReportController::class, 'getRfidDealerships']);
        Route::get('/vehicles', [ExecutiveReportController::class, 'getVehicleReports']);
    });

    // Public Webhooks
    Route::post('/webhooks/libelula', [App\Http\Controllers\Api\WebhookController::class, 'libelula'])->name('api.webhooks.libelula');

    // Protected Routes
    Route::middleware([
        \App\Http\Middleware\QueryTokenAuth::class,
        'auth:sanctum'
    ])->group(function () {
        
        // 1. MAP MODULE (Stations & Locations)
        Route::prefix('map')->group(function () {
            Route::get('/locations', [LocationController::class, 'index']);
            Route::get('/stations', [StationController::class, 'index']);
            Route::get('/stations/lookup', [StationController::class, 'lookup']);
            Route::get('/stations/{station}', [StationController::class, 'show']);
        });

        // 2. WALLET MODULE (Balance & Payments)
        Route::prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'balance']);
            Route::get('/transactions', [WalletController::class, 'history']);
            Route::get('/transactions/{transaction}/invoice', [WalletController::class, 'downloadInvoice']);
            Route::post('/topup', [WalletController::class, 'topup']);
            Route::post('/libelula/checkout', [WalletController::class, 'libelulaCheckout']);
            Route::get('/libelula/status/{transactionId}', [WalletController::class, 'libelulaStatus']);
            Route::delete('/libelula/pending/{transactionId}', [WalletController::class, 'deletePending']);
            Route::post('/tags/{tag}', [WalletController::class, 'updateTag']);
        });

        // 3. SESSIONS MODULE (Charging Control & History)
        Route::prefix('sessions')->group(function () {
            Route::get('/', [ChargingSessionController::class, 'index']);
            Route::get('/{session}', [ChargingSessionController::class, 'show']);
            Route::post('/{station}/start', [ChargingSessionController::class, 'start']);
            Route::post('/{station}/stop', [ChargingSessionController::class, 'stop']);
            Route::post('/{session}/cancel', [ChargingSessionController::class, 'cancel']);
            Route::get('/{session}/invoice', [ChargingSessionController::class, 'downloadInvoice']);
        });

        // 4. PROFILE MODULE (User & Notifications)
        Route::prefix('profile')->group(function () {
            Route::get('/', [AuthController::class, 'profile']);
            Route::post('/', [AuthController::class, 'update']);
            Route::delete('/', [AuthController::class, 'deleteAccount']);
            Route::post('/fcm-token', [AuthController::class, 'updateFcmToken']);
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        });

        // 5. VEHICLES MODULE
        Route::prefix('vehicles')->group(function () {
            Route::get('/', [VehicleController::class, 'index']);
            Route::post('/', [VehicleController::class, 'store']);
            Route::delete('/{vehicle}', [VehicleController::class, 'destroy']);
        });

        // LEGACY REDIRECTS (To avoid breaking the current App immediately if needed)
        // Note: Better to update the App, but keep these for compatibility during transition.
        Route::get('/locations', [LocationController::class, 'index']);
        Route::get('/stations', [StationController::class, 'index']);
        Route::get('/wallet', [WalletController::class, 'balance']);
        Route::get('/sessions', [ChargingSessionController::class, 'index']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::post('/stations/{station}/start', [ChargingSessionController::class, 'start']);
        Route::post('/stations/{station}/stop', [ChargingSessionController::class, 'stop']);
    });
});

// --- SAP INTEGRATION API (V1) ---
Route::prefix('v1/sap')->middleware('auth:sanctum')->group(function () {
    Route::get('/export', [SapExportController::class, 'exportData']);
    Route::post('/sync', [SapExportController::class, 'markSynced']);
});

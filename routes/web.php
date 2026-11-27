<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');

// Libélula sends its payment callback to callback_url (this URL), and the payer's browser
// is redirected here after paying. Both are handled by PaymentReturnController.
Route::match(['get', 'post'], '/payment-return-app', App\Http\Controllers\PaymentReturnController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

Route::get('/disclaimer', [App\Http\Controllers\DisclaimerController::class, 'index'])->name('public.disclaimer');

// REMOTE CONTROL EMERGENCY PAGE
Route::get('/remote/login', [App\Http\Controllers\RemoteControlController::class, 'showLogin'])->name('remote.login');
Route::post('/remote/login', [App\Http\Controllers\RemoteControlController::class, 'login'])->name('remote.login.post');
Route::get('/remote/logout', [App\Http\Controllers\RemoteControlController::class, 'logout'])->name('remote.logout');

Route::get('/remote/control', [App\Http\Controllers\RemoteControlController::class, 'index'])->name('remote.index');
Route::post('/remote/start/{station}', [App\Http\Controllers\RemoteControlController::class, 'start'])->name('remote.start');
Route::post('/remote/stop/{station}', [App\Http\Controllers\RemoteControlController::class, 'stop'])->name('remote.stop');

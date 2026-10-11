<?php

use App\Modules\Auth\Http\Controllers\EmailVerificationController;
use App\Modules\Auth\Http\Controllers\LoginController;
use App\Modules\Auth\Http\Controllers\MeController;
use App\Modules\Auth\Http\Controllers\PasswordResetController;
use App\Modules\Auth\Http\Controllers\RegisterController;
use App\Modules\Auth\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

// Routes are prefixed with /api/v1/auth by AuthServiceProvider.

Route::post('/register', RegisterController::class)->middleware('throttle:auth');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:auth-login');

Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth');

Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:auth'])
    ->name('auth.verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/me', MeController::class);

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:auth');

    Route::get('/tokens', [TokenController::class, 'index']);
    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id');
    Route::delete('/tokens', [TokenController::class, 'destroyAll']);
});

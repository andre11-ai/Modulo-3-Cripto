<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('pagos')->group(function () {
    Route::post('/sesion', [PaymentController::class, 'createSession']);
    Route::post('/intentos', [PaymentController::class, 'createIntent']);
    Route::post('/webhook', [StripeWebhookController::class, 'handle']);
});

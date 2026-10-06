<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // The throttle runs before authentication so invalid keys are rate limited too.
    Route::post('events', [EventController::class, 'store'])
        ->middleware(['throttle:ingest', AuthenticateApiKey::class])
        ->name('api.events.store');
});

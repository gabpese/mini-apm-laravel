<?php

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectReportController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Public demo: a page whose clicks send real events to the API through the JavaScript client.
Route::get('demo', fn () => response()->file(base_path('clients/js/demo.html')))->name('demo');
Route::get('demo/mini-apm.js', fn () => response()->file(base_path('clients/js/mini-apm.js')))->name('demo.client');

Route::middleware(['auth', 'verified'])->group(function () {
    // The dashboard is the list of the user's projects.
    Route::get('dashboard', [ProjectController::class, 'index'])->name('dashboard');

    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectReportController::class, 'overview'])->name('projects.show');
    Route::get('projects/{project}/errors', [ProjectReportController::class, 'errors'])->name('projects.errors');
    Route::get('projects/{project}/versions', [ProjectReportController::class, 'versions'])->name('projects.versions');
    Route::get('projects/{project}/settings', [ProjectController::class, 'edit'])->name('projects.settings');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::post('projects/{project}/keys', [ApiKeyController::class, 'store'])->name('projects.keys.store');
    Route::delete('keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('keys.destroy');
});

require __DIR__.'/settings.php';

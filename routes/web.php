<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Public demo: a page whose clicks send real events to the API through the JavaScript client.
Route::get('demo', fn () => response()->file(base_path('clients/js/demo.html')))->name('demo');
Route::get('demo/mini-apm.js', fn () => response()->file(base_path('clients/js/mini-apm.js')))->name('demo.client');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

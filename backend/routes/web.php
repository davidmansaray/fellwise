<?php

// The Filament panel is mounted at `/` (see AdminPanelProvider).

use App\Http\Controllers\DemoSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/demo/sign-in', DemoSessionController::class)
    ->middleware('throttle:30,1')->name('demo.sign-in');

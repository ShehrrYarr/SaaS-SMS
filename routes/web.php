<?php

use App\Http\Controllers\DemoLoginController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Platform Routes
|--------------------------------------------------------------------------
|
| The public landing page. The Super Admin console lives in routes/central.php
| and every school's portals in routes/tenant.php (both loaded from
| bootstrap/app.php).
|
*/

Volt::route('/', 'welcome')->name('home');

// Public demo: "Enter as Teacher" signs in to the demo school (config/demo.php).
Route::post('demo/{account}', DemoLoginController::class)
    ->middleware('throttle:20,1')
    ->where('account', '[a-z-]+')
    ->name('demo.enter');

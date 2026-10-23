<?php

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

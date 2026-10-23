<?php

namespace App\Livewire\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Sign the user out and return the sign-in URL they should land on.
     */
    public function __invoke(): string
    {
        $signInUrl = tenant() ? route('tenant.login') : route('central.login');

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        return $signInUrl;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Demo;
use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Enter as Teacher" on the landing page: signs the visitor in to the demo
 * school as that showcased account, with no password.
 */
class DemoLoginController extends Controller
{
    public function __invoke(Request $request, string $account): RedirectResponse
    {
        abort_unless(Demo::enabled(), 404);

        $username = Demo::accounts()[$account]['username'] ?? abort(404);
        $school = Demo::school();

        // Missing while the nightly rebuild runs (a few minutes).
        if ($school === null || $school->isSuspended()) {
            return $this->unavailable();
        }

        return tenancy()->run($school, function () use ($request, $username) {
            $user = User::query()->where('username', $username)->first();

            if ($user === null || ! $user->isActive()) {
                return $this->unavailable();
            }

            Auth::guard('web')->logout();
            Auth::guard('web')->login($user);
            $request->session()->regenerate(true);

            return redirect()->to(Portal::homeFor($user) ?? route('tenant.home'));
        });
    }

    private function unavailable(): RedirectResponse
    {
        return redirect()->to(route('home').'#demo')
            ->with('demo-unavailable', __('The demo school is being refreshed. Please try again in a few minutes.'));
    }
}

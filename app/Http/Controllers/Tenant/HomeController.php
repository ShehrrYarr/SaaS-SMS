<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Portal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /{school}: send the user to the first portal they can enter.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $home = Portal::homeFor($request->user());

        abort_if($home === null, 403, __('Your account has not been given access to any portal yet. Please contact your school administrator.'));

        return redirect()->to($home);
    }
}

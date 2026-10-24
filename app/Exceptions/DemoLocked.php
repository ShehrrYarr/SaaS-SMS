<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A change the public demo doesn't allow. Livewire actions show it as a
 * warning toast (AppServiceProvider); other requests get a 403 page.
 */
class DemoLocked extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(403, $message);
    }

    public static function signIns(): self
    {
        return new self(__('Passwords and usernames can\'t be changed in the demo, so every visitor can sign in.'));
    }

    public static function showcasedAccount(): self
    {
        return new self(__('This account is one of the demo sign-ins, so it can\'t be deactivated or deleted.'));
    }

    public static function settings(): self
    {
        return new self(__('The demo school\'s name, logo and settings can\'t be changed.'));
    }
}

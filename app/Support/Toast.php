<?php

namespace App\Support;

/**
 * Toasts that survive a redirect: flashed to the session and shown by the
 * next page the layout renders (resources/js/ui.js picks them up).
 */
class Toast
{
    private const KEY = 'toasts';

    public static function flash(string $message, string $type = 'success'): void
    {
        session()->flash(self::KEY, [...session()->get(self::KEY, []), ['type' => $type, 'message' => $message]]);
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public static function pending(): array
    {
        return session()->get(self::KEY, []);
    }
}

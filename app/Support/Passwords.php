<?php

namespace App\Support;

/**
 * Temporary passwords that an administrator reads out or copies to a user.
 * There is no email in the system, so these are shown on screen once and the
 * user must change them at first sign-in.
 */
class Passwords
{
    // No look-alikes (0/O, 1/l/I), so a password read off a screen types correctly.
    private const LETTERS = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    /**
     * A random password with letters and digits (satisfying Password::defaults()).
     */
    public static function generate(int $length = 12): string
    {
        $alphabet = self::LETTERS.self::DIGITS;

        $characters = [
            self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)],
            self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)],
        ];

        while (count($characters) < $length) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Fisher-Yates with a CSPRNG, so the guaranteed letter and digit aren't always first.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}

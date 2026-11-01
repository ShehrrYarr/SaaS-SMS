<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number as people write them: digits, with an optional leading plus,
 * spaces, dashes, dots and brackets ("+92 300 1234567", "(042) 111-222").
 */
class PhoneNumber implements ValidationRule
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^\+?[0-9()\-.\s]+$/', $value) === 1
            && preg_match_all('/[0-9]/', $value) >= 3;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail(__('Enter a valid phone number: digits, with spaces or dashes if you like.'));
        }
    }
}

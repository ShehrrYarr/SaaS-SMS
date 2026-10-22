<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A school slug is the first URL segment (/{slug}/admin), so it must be
 * URL-safe and must not collide with a platform route.
 */
class SchoolSlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            $fail(__('The :attribute may only contain lowercase letters, numbers and single hyphens.'));

            return;
        }

        if (strlen($value) < 3 || strlen($value) > 60) {
            $fail(__('The :attribute must be between 3 and 60 characters.'));

            return;
        }

        if (in_array($value, config('tenancy.reserved_slugs'), true)) {
            $fail(__('The :attribute ":value" is reserved. Please choose another.', ['value' => $value]));
        }
    }
}

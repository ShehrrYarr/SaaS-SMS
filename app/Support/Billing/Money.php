<?php

namespace App\Support\Billing;

use Illuminate\Support\Number;

/**
 * Formats SaaS billing amounts (plan prices, subscription payments), which
 * are always in the platform currency of config/plans.php.
 */
class Money
{
    public static function platform(float|int|string|null $amount): string
    {
        $currency = static::currency();
        $amount = (float) ($amount ?? 0);

        if (extension_loaded('intl')) {
            return Number::currency($amount, in: $currency, locale: app()->getLocale());
        }

        return $currency.' '.number_format($amount, 2);
    }

    public static function currency(): string
    {
        return strtoupper((string) config('plans.currency', 'USD'));
    }
}

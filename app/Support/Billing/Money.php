<?php

namespace App\Support\Billing;

use Illuminate\Support\Number;

/**
 * Money formatting.
 *
 *  - platform(): SaaS billing amounts (plan prices, subscription payments),
 *    always in the platform currency of config/plans.php;
 *  - school(): a school's own amounts (fees, expenses, salaries), with the
 *    currency symbol from its settings.
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

    /**
     * "$1,250.00" in the active school's currency symbol; negative amounts
     * as "-$20.00". A symbol written in letters is spaced: "Rs 1,250.00".
     */
    public static function school(float|int|string|null $amount): string
    {
        $amount = round((float) ($amount ?? 0), 2);
        $symbol = trim((string) (tenant()?->setting('currency_symbol') ?? ''));

        if (preg_match('/\p{L}$/u', $symbol)) {
            $symbol .= ' ';
        }

        return ($amount < 0 ? '-' : '').$symbol.number_format(abs($amount), 2);
    }

    /**
     * An amount for a form field: "1500.00" → "1500", "12.50" → "12.5",
     * null → "".
     */
    public static function input(float|int|string|null $amount): string
    {
        return $amount === null || $amount === '' ? '' : rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }
}

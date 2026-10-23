<?php

namespace App\Support\Locale;

use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Choices for a school's regional settings (currency, timezone, date format),
 * used when provisioning a school and on the school settings page.
 */
class LocaleOptions
{
    /**
     * Common school currencies: ISO code => symbol. A school can still type a
     * different symbol.
     *
     * @var array<string, string>
     */
    public const CURRENCIES = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'PKR' => 'Rs',
        'INR' => '₹',
        'BDT' => '৳',
        'LKR' => 'Rs',
        'NPR' => 'Rs',
        'AED' => 'AED',
        'SAR' => 'SAR',
        'QAR' => 'QAR',
        'KWD' => 'KD',
        'OMR' => 'OMR',
        'BHD' => 'BD',
        'EGP' => 'E£',
        'TRY' => '₺',
        'NGN' => '₦',
        'KES' => 'KSh',
        'GHS' => 'GH₵',
        'ZAR' => 'R',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'NZD' => 'NZ$',
        'SGD' => 'S$',
        'MYR' => 'RM',
        'IDR' => 'Rp',
        'PHP' => '₱',
    ];

    /**
     * PHP date formats offered to schools.
     *
     * @var list<string>
     */
    public const DATE_FORMATS = ['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'M d, Y'];

    /**
     * @return array<string, string> code => "PKR (Rs)"
     */
    public static function currencies(): array
    {
        $options = [];

        foreach (self::CURRENCIES as $code => $symbol) {
            $options[$code] = $code === $symbol ? $code : "{$code} ({$symbol})";
        }

        return $options;
    }

    public static function symbolFor(string $code): string
    {
        return self::CURRENCIES[$code] ?? $code;
    }

    /**
     * @return array<string, string> identifier => label, grouped by region in display order
     */
    public static function timezones(): array
    {
        $options = [];

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $options[$zone] = str_replace(['/', '_'], [' / ', ' '], $zone);
        }

        return $options;
    }

    /**
     * @return array<string, string> format => today's date in that format
     */
    public static function dateFormats(): array
    {
        $today = Carbon::today();

        return collect(self::DATE_FORMATS)->mapWithKeys(fn (string $format) => [$format => $today->format($format)])->all();
    }
}

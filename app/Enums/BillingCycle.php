<?php

namespace App\Enums;

enum BillingCycle: string
{
    use EnumOptions;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::HalfYearly => __('Half-yearly'),
            default => $this->defaultLabel(),
        };
    }

    /**
     * Length of one billing period.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }

    /**
     * "per month", "per year"... for prices.
     */
    public function per(): string
    {
        return match ($this) {
            self::Monthly => __('per month'),
            self::Quarterly => __('per quarter'),
            self::HalfYearly => __('per half-year'),
            self::Yearly => __('per year'),
        };
    }
}

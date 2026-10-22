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
}

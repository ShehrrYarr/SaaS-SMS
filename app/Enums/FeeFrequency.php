<?php

namespace App\Enums;

enum FeeFrequency: string
{
    use EnumOptions;

    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => __('One-time'),
            self::HalfYearly => __('Half-yearly'),
            default => $this->defaultLabel(),
        };
    }
}

<?php

namespace App\Enums;

enum LateFeeType: string
{
    use EnumOptions;

    case Fixed = 'fixed';
    case Daily = 'daily';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => __('Fixed amount'),
            self::Daily => __('Per day'),
            default => $this->defaultLabel(),
        };
    }
}

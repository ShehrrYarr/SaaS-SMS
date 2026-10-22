<?php

namespace App\Enums;

enum AdjustmentType: string
{
    use EnumOptions;

    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => __('Fixed amount'),
            default => $this->defaultLabel(),
        };
    }
}

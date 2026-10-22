<?php

namespace App\Enums;

enum PayrollStatus: string
{
    use EnumOptions;

    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Approved => 'info',
            self::Paid => 'success',
        };
    }
}

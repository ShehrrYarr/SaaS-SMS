<?php

namespace App\Enums;

/**
 * Where a library fine stands.
 */
enum FineStatus: string
{
    use EnumOptions;

    case None = 'none';
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Waived = 'waived';
    case Invoiced = 'invoiced';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No fine'),
            self::Invoiced => __('Added to fees'),
            default => $this->defaultLabel(),
        };
    }

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::None => 'secondary',
            self::Unpaid => 'danger',
            self::Paid => 'success',
            self::Waived => 'secondary',
            self::Invoiced => 'info',
        };
    }
}

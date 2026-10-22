<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    use EnumOptions;

    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Partial => __('Partially paid'),
            default => $this->defaultLabel(),
        };
    }

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'warning',
            self::Partial => 'info',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Cancelled => 'secondary',
        };
    }
}

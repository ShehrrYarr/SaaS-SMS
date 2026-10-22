<?php

namespace App\Enums;

enum LeaveAppliesTo: string
{
    use EnumOptions;

    case Staff = 'staff';
    case Student = 'student';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Both => __('Staff & students'),
            default => $this->defaultLabel(),
        };
    }
}

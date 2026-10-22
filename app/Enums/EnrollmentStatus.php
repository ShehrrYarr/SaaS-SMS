<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    use EnumOptions;

    case Active = 'active';
    case Promoted = 'promoted';
    case Retained = 'retained';
    case Graduated = 'graduated';
    case Transferred = 'transferred';
    case Left = 'left';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Promoted => 'primary',
            self::Retained => 'warning',
            self::Graduated => 'info',
            self::Transferred => 'secondary',
            self::Left => 'secondary',
        };
    }
}

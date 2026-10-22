<?php

namespace App\Enums;

enum AcademicPeriodStatus: string
{
    use EnumOptions;

    case Current = 'current';
    case Upcoming = 'upcoming';
    case Archived = 'archived';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Current => 'success',
            self::Upcoming => 'info',
            self::Archived => 'secondary',
        };
    }
}

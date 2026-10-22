<?php

namespace App\Enums;

enum ExamStatus: string
{
    use EnumOptions;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Published = 'published';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Scheduled => 'info',
            self::Ongoing => 'warning',
            self::Completed => 'primary',
            self::Published => 'success',
        };
    }
}

<?php

namespace App\Enums;

enum HomeworkStatus: string
{
    use EnumOptions;

    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Published => 'success',
            self::Closed => 'dark',
        };
    }
}

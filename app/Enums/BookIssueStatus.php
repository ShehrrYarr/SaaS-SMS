<?php

namespace App\Enums;

enum BookIssueStatus: string
{
    use EnumOptions;

    case Issued = 'issued';
    case Returned = 'returned';
    case Lost = 'lost';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Issued => 'info',
            self::Returned => 'success',
            self::Lost => 'danger',
        };
    }
}

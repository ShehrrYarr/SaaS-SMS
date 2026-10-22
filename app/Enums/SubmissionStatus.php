<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    use EnumOptions;

    case Pending = 'pending';
    case Submitted = 'submitted';
    case Late = 'late';
    case Graded = 'graded';
    case Returned = 'returned';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Submitted => 'info',
            self::Late => 'warning',
            self::Graded => 'success',
            self::Returned => 'primary',
        };
    }
}

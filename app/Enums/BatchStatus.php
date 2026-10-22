<?php

namespace App\Enums;

enum BatchStatus: string
{
    use EnumOptions;

    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Queued => 'secondary',
            self::Processing => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}

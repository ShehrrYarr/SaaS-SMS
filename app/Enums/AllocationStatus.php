<?php

namespace App\Enums;

enum AllocationStatus: string
{
    use EnumOptions;

    case Active = 'active';
    case Ended = 'ended';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Ended => 'secondary',
        };
    }
}

<?php

namespace App\Enums;

enum SchoolStatus: string
{
    use EnumOptions;

    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}

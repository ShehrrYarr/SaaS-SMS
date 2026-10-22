<?php

namespace App\Enums;

enum UserStatus: string
{
    use EnumOptions;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'secondary',
            self::Suspended => 'danger',
        };
    }
}

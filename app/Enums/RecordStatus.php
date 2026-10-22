<?php

namespace App\Enums;

enum RecordStatus: string
{
    use EnumOptions;

    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'secondary',
        };
    }
}

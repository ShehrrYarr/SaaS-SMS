<?php

namespace App\Enums;

enum StudentStatus: string
{
    use EnumOptions;

    case Active = 'active';
    case Graduated = 'graduated';
    case Transferred = 'transferred';
    case Left = 'left';
    case Suspended = 'suspended';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Graduated => 'primary',
            self::Transferred => 'info',
            self::Left => 'secondary',
            self::Suspended => 'danger',
        };
    }
}

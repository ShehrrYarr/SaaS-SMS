<?php

namespace App\Enums;

enum NoticeAudience: string
{
    use EnumOptions;

    case All = 'all';
    case Staff = 'staff';
    case Teachers = 'teachers';
    case Students = 'students';
    case Parents = 'parents';

    public function label(): string
    {
        return match ($this) {
            self::All => __('Everyone'),
            self::Staff => __('All staff'),
            default => $this->defaultLabel(),
        };
    }

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::All => 'primary',
            self::Staff => 'info',
            self::Teachers => 'info',
            self::Students => 'success',
            self::Parents => 'secondary',
        };
    }
}

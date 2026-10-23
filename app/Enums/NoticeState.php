<?php

namespace App\Enums;

/**
 * Where a notice is in its life, from its publish and expiry dates. Never stored.
 */
enum NoticeState: string
{
    use EnumOptions;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Expired = 'expired';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Scheduled => 'info',
            self::Live => 'success',
            self::Expired => 'warning',
        };
    }
}

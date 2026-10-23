<?php

namespace App\Enums;

/**
 * Where a school stands with its subscription. Derived from the school's
 * trial and paid-until dates; never stored.
 */
enum SubscriptionState: string
{
    use EnumOptions;

    case Active = 'active';
    case Trial = 'trial';
    case Grace = 'grace';
    case Expired = 'expired';
    case Open = 'open';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Paid'),
            self::Trial => __('Trial'),
            self::Grace => __('Grace period'),
            self::Expired => __('Expired'),
            self::Open => __('No end date'),
        };
    }

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trial => 'info',
            self::Grace => 'warning',
            self::Expired => 'danger',
            self::Open => 'secondary',
        };
    }

    /**
     * Whether the school may still use the system.
     */
    public function grantsAccess(): bool
    {
        return $this !== self::Expired;
    }
}

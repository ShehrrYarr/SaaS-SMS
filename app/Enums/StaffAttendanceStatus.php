<?php

namespace App\Enums;

enum StaffAttendanceStatus: string
{
    use EnumOptions;

    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case HalfDay = 'half_day';
    case OnLeave = 'on_leave';

    public function label(): string
    {
        return match ($this) {
            self::HalfDay => __('Half day'),
            self::OnLeave => __('On leave'),
            default => $this->defaultLabel(),
        };
    }

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Absent => 'danger',
            self::Late => 'warning',
            self::HalfDay => 'info',
            self::OnLeave => 'secondary',
        };
    }
}

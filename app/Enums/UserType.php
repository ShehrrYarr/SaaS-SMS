<?php

namespace App\Enums;

enum UserType: string
{
    use EnumOptions;

    case SuperAdmin = 'super_admin';
    case SchoolAdmin = 'school_admin';
    case Teacher = 'teacher';
    case Student = 'student';
    case Parent = 'parent';
    case Hr = 'hr';
    case Accountant = 'accountant';
    case Librarian = 'librarian';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Hr => __('HR'),
            default => $this->defaultLabel(),
        };
    }

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::SuperAdmin => 'dark',
            self::SchoolAdmin => 'primary',
            self::Teacher => 'info',
            self::Student => 'success',
            self::Parent => 'secondary',
            self::Hr => 'warning',
            self::Accountant => 'warning',
            self::Librarian => 'info',
            self::Staff => 'secondary',
        };
    }

    /**
     * Staff accounts have a staff profile and can hold staff portal roles.
     */
    public function isStaff(): bool
    {
        return ! in_array($this, [self::SuperAdmin, self::Student, self::Parent], true);
    }

    /**
     * The built-in role (config/access.php) a new account of this type gets.
     */
    public function defaultRole(): ?string
    {
        return match ($this) {
            self::SchoolAdmin => 'School Admin',
            self::Teacher => 'Teacher',
            self::Student => 'Student',
            self::Parent => 'Parent',
            self::Hr => 'HR',
            self::Accountant => 'Accountant',
            self::Librarian => 'Librarian',
            self::SuperAdmin, self::Staff => null,
        };
    }
}

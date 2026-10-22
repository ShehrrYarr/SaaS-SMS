<?php

namespace App\Enums;

enum SubjectType: string
{
    use EnumOptions;

    case Theory = 'theory';
    case Practical = 'practical';
    case Optional = 'optional';

    /**
     * Bootstrap contextual colour for badges (bg-{color}-subtle text-{color}).
     */
    public function color(): string
    {
        return match ($this) {
            self::Theory => 'primary',
            self::Practical => 'info',
            self::Optional => 'secondary',
        };
    }
}

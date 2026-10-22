<?php

namespace App\Enums;

enum ClassroomType: string
{
    use EnumOptions;

    case Classroom = 'classroom';
    case Lab = 'lab';
    case Hall = 'hall';
    case Library = 'library';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lab => __('Laboratory'),
            default => $this->defaultLabel(),
        };
    }
}

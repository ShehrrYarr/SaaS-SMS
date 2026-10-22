<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Shared helpers for backed enums used in forms, filters and badges.
 */
trait EnumOptions
{
    public function label(): string
    {
        return $this->defaultLabel();
    }

    protected function defaultLabel(): string
    {
        return __(Str::headline($this->value));
    }

    /**
     * @return array<string, string> value => label, for <select> options.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

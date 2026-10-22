<?php

namespace App\Enums;

enum Gender: string
{
    use EnumOptions;

    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
}

<?php

namespace App\Enums;

enum GuardianRelation: string
{
    use EnumOptions;

    case Father = 'father';
    case Mother = 'mother';
    case Guardian = 'guardian';
    case Other = 'other';
}

<?php

namespace App\Enums;

enum HostelType: string
{
    use EnumOptions;

    case Boys = 'boys';
    case Girls = 'girls';
    case Mixed = 'mixed';
}

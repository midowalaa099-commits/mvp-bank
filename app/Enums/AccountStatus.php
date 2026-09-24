<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
}

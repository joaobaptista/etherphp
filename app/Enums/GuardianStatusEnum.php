<?php

namespace App\Enums;

enum GuardianStatusEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

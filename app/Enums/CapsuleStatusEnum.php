<?php

namespace App\Enums;

enum CapsuleStatusEnum: string
{
    case Locked = 'LOCKED';
    case Unlocked = 'UNLOCKED';
    case Archived = 'ARCHIVED';
}

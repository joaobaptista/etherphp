<?php

namespace App\Enums;

enum MessageStatusEnum: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Delivered = 'delivered';
}

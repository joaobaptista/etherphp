<?php

namespace App\Enums;

enum PickerSessionStatusEnum: string
{
    case Pending = 'PENDING';
    case Picking = 'PICKING';
    case SelectionReady = 'SELECTION_READY';
    case Importing = 'IMPORTING';
    case Completed = 'COMPLETED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
    case Error = 'ERROR';
}

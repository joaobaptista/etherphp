<?php

namespace App\Enums;

enum IntegrationStatusEnum: string
{
    case Active = 'ACTIVE';
    case Revoked = 'REVOKED';
    case Error = 'ERROR';
}

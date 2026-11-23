<?php

namespace App\Enums;

enum CheckoutMethodEnum: string
{
    /** Recurring card subscription (Mercado Pago preapproval, hosted checkout). */
    case Card = 'card';
    /** One-off PIX payment for a single billing period (no automatic renewal). */
    case Pix = 'pix';
}

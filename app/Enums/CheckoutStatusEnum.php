<?php

namespace App\Enums;

enum CheckoutStatusEnum: string
{
    /** Waiting for the payer (PIX not paid yet, or card subscription not completed/charged). */
    case Pending = 'pending';
    /** A payment was approved and access was granted. */
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** PIX expired unpaid. */
    case Expired = 'expired';
    case Canceled = 'canceled';
}

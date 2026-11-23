<?php

namespace App\Exceptions;

use RuntimeException;

/** Mercado Pago is unreachable, not configured, or rejected the request. */
class PaymentGatewayException extends RuntimeException {}

<?php

declare(strict_types=1);

namespace Payzum\Http;

use Payzum\Errors\PayzumException;

/**
 * The request never produced a response: DNS, TLS, timeout, connection reset.
 *
 * Distinct from an API error because the outcome is genuinely unknown. A
 * timeout on invoice creation may mean the invoice was created anyway, which is
 * exactly why the client will not retry that call blindly.
 */
final class TransportException extends PayzumException
{
}

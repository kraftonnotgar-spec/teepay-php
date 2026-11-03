<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * 409 - idempotency key reused with a different request, or a payout quote expired or changed.
 */
class ConflictError extends ApiError
{
}

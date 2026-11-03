<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * The request timed out. The operation may still have happened: retry with the same idempotency key.
 */
class TimeoutError extends ConnectionError
{
}

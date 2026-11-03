<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * 429 - too many requests. Wait `$retryAfter` seconds before retrying.
 */
class RateLimitError extends ApiError
{
}

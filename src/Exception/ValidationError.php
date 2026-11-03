<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * 400/422 - a field, header or query parameter is invalid, or the amount/market is not allowed.
 */
class ValidationError extends ApiError
{
}

<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * 403 - the key lacks the scope, or the account cannot use this feature now.
 */
class PermissionError extends ApiError
{
}

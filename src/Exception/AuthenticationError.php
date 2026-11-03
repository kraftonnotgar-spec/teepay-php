<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * 401 - the API key is missing, invalid, revoked, expired or for the wrong environment.
 */
class AuthenticationError extends ApiError
{
}

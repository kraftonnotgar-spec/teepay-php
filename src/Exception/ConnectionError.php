<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * The request could not reach TeePay or no response arrived. On a create, the operation may still have happened: retry with the same idempotency key.
 */
class ConnectionError extends TeePayError
{
}

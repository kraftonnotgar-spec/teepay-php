<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * The TeePay API answered with an error status. Subclasses narrow it down by HTTP status.
 *
 * `$errorCode` is the stable machine-readable code (for example `idempotency_conflict`);
 * `$requestId` is the value to quote when contacting TeePay support.
 */
class ApiError extends TeePayError
{
    /**
     * @param array<string, string> $headers Response headers, lower-case names.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly array $headers = [],
        public readonly mixed $body = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

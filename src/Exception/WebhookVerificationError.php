<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * A webhook signature, timestamp or body failed verification. Respond 400 and do not process it.
 */
class WebhookVerificationError extends TeePayError
{
}

<?php

// Webhook endpoint: verify the signature on the raw body, de-duplicate, then handle the event.
// Serve with: TEEPAY_WEBHOOK_SECRET=whsec_... php -S localhost:8080 examples/webhook.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TeePay\Exception\WebhookVerificationError;
use TeePay\Webhook;

$payload = (string) file_get_contents('php://input'); // raw bytes, before any json_decode
$headers = function_exists('getallheaders') ? getallheaders() : $_SERVER;

try {
    $event = Webhook::verify($payload, $headers, (string) getenv('TEEPAY_WEBHOOK_SECRET'));
} catch (WebhookVerificationError $e) {
    http_response_code(400);
    exit;
}

// Deliveries can repeat: process each event id once (use your database in production).
$seen = sys_get_temp_dir() . '/teepay-event-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $event->id);
if (file_exists($seen)) {
    http_response_code(200);
    exit;
}
touch($seen);

switch ($event->event) {
    case 'payment.completed':
        // Verified and posted to your TeePay ledger: fulfil the order.
        $reference = $event->data->reference;
        $amount = $event->data->money->amount_minor; // string, minor units
        $source = $event->data->source;              // checkout_session, control_number, ... or null
        error_log("Paid {$reference}: {$amount} " . ($source?->type ?? 'push'));
        break;
    case 'payment.failed':
    case 'payment.voided':
    case 'payment.expired':
        break;
    case 'payout.completed':
    case 'payout.failed':
    case 'payout.reversed':
        break;
}

// Respond quickly; do slow work in a queue.
http_response_code(200);

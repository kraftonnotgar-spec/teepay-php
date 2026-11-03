<?php

// Mobile money push: send a USSD payment prompt and poll until the payment is final.
// Run: TEEPAY_API_KEY=tp_live_... php examples/collection.php +255712345678

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TeePay\Exception\ApiError;
use TeePay\Exception\ConnectionError;
use TeePay\Exception\ValidationError;
use TeePay\TeePay;

$teepay = new TeePay(); // reads TEEPAY_API_KEY
$phone = $argv[1] ?? '+255712345678';
$orderId = 'order-' . date('Ymd-His');

$capabilities = $teepay->capabilities();
if (!$capabilities->features->collection_create) {
    exit("Mobile money push is not enabled for this key.\n");
}

try {
    $collection = $teepay->collections->create([
        'money' => ['amount_minor' => '100000', 'currency' => 'TZS'], // TZS 1,000.00
        'customer' => ['country' => 'TZ', 'phone' => $phone],
        'description' => 'Order ' . $orderId,
    ], ['idempotency_key' => $orderId]);
} catch (ValidationError $e) {
    exit("Rejected: {$e->errorCode} ({$e->getMessage()})\n");
} catch (ConnectionError|ApiError $e) {
    // Not a payment outcome: retry with the same idempotency key or read the collection.
    exit("Try again with the same key {$orderId}: {$e->getMessage()}\n");
}

echo "Prompt sent. Reference {$collection->public_reference}, status {$collection->status}\n";

for ($i = 0; $i < 40; $i++) {
    sleep(4);
    $collection = $teepay->collections->retrieve($collection->public_reference);
    if (in_array($collection->status, ['succeeded', 'failed', 'reversed', 'refunded'], true)) {
        break;
    }
}

echo "Final status: {$collection->status}" . ($collection->status_detail ? " ({$collection->status_detail})" : '') . "\n";

<?php

// Hosted checkout: create a session on your server and redirect the customer to checkout_url.
// Serve with: php -S localhost:8080 examples/checkout-session.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TeePay\TeePay;

$teepay = new TeePay(); // reads TEEPAY_API_KEY

// In a real shop these come from your order record.
$orderId = 'order-1001';

$session = $teepay->checkoutSessions->create([
    'title' => 'Order 1001',
    'description' => '2 x Coffee bag',
    'amount_minor' => 500000, // integers are sent as "500000"
    'currency' => 'TZS',
    'reference' => $orderId,
    'metadata' => ['cart' => 'c-77'],
], ['idempotency_key' => $orderId]);

if ($session->getLastResponse()?->idempotentReplayed) {
    // Same key and body as before: this is the session created earlier.
}

if (PHP_SAPI === 'cli') {
    echo $session->checkout_url, PHP_EOL;
    exit;
}

header('Location: ' . $session->checkout_url, true, 303);

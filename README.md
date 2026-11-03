# TeePay PHP SDK

The official PHP library for the [TeePay](https://teepay.me) API: mobile money push payments (USSD prompts),
hosted checkout sessions, Tanzanian Control Numbers, payouts and webhook verification.

- PHP 8.1 or newer, with `ext-curl` and `ext-json`. No other runtime dependencies.
- Automatic idempotency keys, safe retries with backoff, timeouts and typed exceptions.
- Every public endpoint of the TeePay API (`https://api.teepay.me/v1`).

Full documentation: <https://docs.teepay.me/docs/sdks/php>

## Install

```bash
composer require teepay/teepay-php
```

## Configure

Create a secret API key in the TeePay dashboard under **Developers → API keys** and keep it on your server
(an environment variable or secret store), never in a browser, mobile app or repository.

```php
require 'vendor/autoload.php';

$teepay = new \TeePay\TeePay(getenv('TEEPAY_API_KEY'));

// or with options
$teepay = new \TeePay\TeePay([
    'api_key' => getenv('TEEPAY_API_KEY'),
    'timeout' => 20,      // seconds per attempt, default 30
    'max_retries' => 3,   // default 2
]);
```

With no key argument the client reads `TEEPAY_API_KEY` from the environment.

### Sandbox and live

The key decides the environment: `tp_test_…` keys are **sandbox**, `tp_live_…` keys are **live**. Both use
`https://api.teepay.me`.

```php
echo $teepay->environment; // "sandbox" or "live"
```

Money flows (mobile money push, checkout sessions, Control Numbers and payouts) currently run in live only.
Use a test key to check authentication, scopes, capability discovery and your error handling, then test
money flows in live with small amounts. Ask the API what your key can do:

```php
$capabilities = $teepay->capabilities();
if (!$capabilities->features->collection_create) {
    // hide mobile money push in your UI
}
print_r($capabilities['supported_countries']); // ["TZ"]
```

## Quickstart

### Mobile money push (USSD prompt)

Send a payment prompt to the customer's phone. They approve it with their mobile money PIN.

```php
$collection = $teepay->collections->create([
    'money' => ['amount_minor' => '500000', 'currency' => 'TZS'], // TZS 5,000.00
    'customer' => ['country' => 'TZ', 'phone' => '+255712345678', 'name' => 'Asha Juma'],
    'description' => 'Order 1001',
], ['idempotency_key' => 'order-1001']);

echo $collection->public_reference; // TP8K2M4QZ1
echo $collection->status;           // "pending"
```

A `202` response means TeePay accepted the request, **not** that the customer paid. Fulfil the order only after
the `payment.completed` webhook, or when a read returns `succeeded`:

```php
$collection = $teepay->collections->retrieve('TP8K2M4QZ1');
if ($collection->status === 'succeeded') {
    // deliver the order
} elseif ($collection->status === 'failed') {
    // $collection->status_detail is "cancelled" or "expired" when known
}
```

### Hosted checkout session

Create a TeePay-hosted payment page and redirect the customer to it.

```php
$session = $teepay->checkoutSessions->create([
    'title' => 'Order 1001',
    'description' => '2 x Coffee bag',
    'amount_minor' => '500000',
    'currency' => 'TZS',
    'reference' => 'order-1001',          // your order ID, echoed back
    'metadata' => ['cart' => 'c-77'],     // keys must be lower-case
], ['idempotency_key' => 'order-1001']);

header('Location: ' . $session->checkout_url, true, 303);
```

Later, `$teepay->checkoutSessions->retrieve($session->id)` returns `status: "paid"` and `payment.reference`
only after TeePay verified the payment. A browser redirect back to your site is never proof of payment.

### Verify a webhook

TeePay signs each webhook with your signing secret (`whsec_…`, from **Developers → Webhooks**). Verify the
**raw** body before trusting it:

```php
use TeePay\Exception\WebhookVerificationError;
use TeePay\Webhook;

$payload = file_get_contents('php://input');   // raw bytes, before json_decode
try {
    $event = Webhook::verify($payload, getallheaders(), getenv('TEEPAY_WEBHOOK_SECRET'));
} catch (WebhookVerificationError $e) {
    http_response_code(400);
    exit;
}

// Deduplicate on $event->id (also sent as X-TeePay-Event-ID): deliveries can repeat.
switch ($event->event) {
    case 'payment.completed':
        // $event->data->reference, $event->data->money->amount_minor, $event->data->source
        break;
    case 'payout.failed':
        break;
}
http_response_code(200);
```

`verify()` checks the `v1=` HMAC-SHA256 signature in constant time and rejects timestamps more than 300 seconds
from now (pass a fourth argument to change the tolerance). Headers may come from `getallheaders()`, `$_SERVER`
(`HTTP_X_TEEPAY_SIGNATURE`) or a PSR-7 `$request->getHeaders()`. `$teepay->webhooks->verify(...)` does the same.

## Reading results

Results are `TeePay\TeePayObject` values with the API's exact snake_case field names. Read them as arrays or
properties; nested objects read as properties are objects too:

```php
$session['checkout_url'];
$session->checkout_url;
$session->payment?->reference;
$session->toArray();          // plain array
json_encode($session);        // original JSON shape

$info = $session->getLastResponse();
$info->status;               // 201
$info->requestId;            // quote this to TeePay support
$info->idempotentReplayed;   // true when the same key returned an earlier result
$info->rateLimit;            // ['limit' => 120, 'remaining' => 117, 'reset' => 42] or null
```

Amounts are integer minor units. Request fields named `*_minor` are sent as strings; the SDK converts PHP
integers for you (`'amount_minor' => 500000` becomes `"500000"`). Payout quotes take `money.amount` as an integer.

## Idempotency

Creates that move money accept an idempotency key so a retry never charges twice:

| Method | Where the key goes |
|---|---|
| `collections->create()` | `Idempotency-Key` header, from `['idempotency_key' => …]` |
| `checkoutSessions->create()` | `Idempotency-Key` header (8 to 120 characters) |
| `controlNumbers->create()` | `Idempotency-Key` header |
| `disbursements->create()` | `idempotency_key` in the body |

If you do not pass a key the SDK generates one (`sdk-<uuid>`) and reuses it for its own retries of that call.
For real integrations, **derive the key from your own record** (`order-1001`, `payout-2026-10-0001`) so a retry
after a crash or timeout reuses it. Same key and body returns the original result; same key with a different
body is a `409` (`ConflictError`). After a final failure (for example the customer cancelled), use a new key
such as `order-1001-2`.

A Control Number create that returns status `202` (`$number->getLastResponse()->status`) is still being issued:
call `create()` again with the **same** key. A payout create that returns `202` has an uncertain outcome: read it
with `disbursements->retrieve()` and never retry with a new key.

## Errors

Every error extends `TeePay\Exception\TeePayError`. API errors extend `ApiError` and carry `status`,
`errorCode`, `requestId`, `retryAfter`, `headers` and the decoded `body`.

| Exception | When |
|---|---|
| `AuthenticationError` | 401 `invalid_credential`: wrong, revoked, expired or wrong-environment key |
| `PermissionError` | 403 `scope_denied`, `access_denied`, `developer_access_denied` |
| `AccountRestrictedError` | 403 `account_restricted` (a `PermissionError`): contact TeePay support |
| `NotFoundError` | 404 `not_found` |
| `ConflictError` | 409 `idempotency_conflict`, or a payout quote that expired or changed |
| `ValidationError` | 422 `invalid_request`, `unsupported_market`, `invalid_amount`, `collection_limit_exceeded`, … |
| `RateLimitError` | 429 `rate_limited`; wait `retryAfter` seconds |
| `ApiError` | 5xx and anything else |
| `ConnectionError` / `TimeoutError` | No response arrived. The operation may still have happened. |

```php
use TeePay\Exception;

try {
    $teepay->collections->create($params, ['idempotency_key' => 'order-1001']);
} catch (Exception\ValidationError $e) {
    if ($e->errorCode === 'unsupported_market') { /* check capabilities */ }
} catch (Exception\AccountRestrictedError $e) {
    // show "contact support"; retrying will not help
} catch (Exception\RateLimitError $e) {
    sleep($e->retryAfter ?? 1);
} catch (Exception\ConnectionError | Exception\ApiError $e) {
    // Not a payment outcome: retry with the SAME key or read the collection.
    error_log('TeePay error, request ' . ($e instanceof Exception\ApiError ? $e->requestId : '-'));
}
```

Mistakes the SDK can detect locally (an upper-case JSON key such as a metadata key, a float amount, an empty ID)
throw `\InvalidArgumentException` before any request is sent.

## Retries and timeouts

The SDK retries a request only when repeating it is safe: reads, and creates that carry an idempotency key. It
retries connection errors, timeouts, `408`, `429`, `500`, `502`, `503` and `504` up to `max_retries` times (default
2), waiting for `Retry-After` when the API sends it and otherwise backing off exponentially with jitter. A payout
quote (which has no key) is only retried on `429`. `4xx` errors are never retried.

Per call options: `['idempotency_key' => …, 'timeout' => 10, 'max_retries' => 0]`.

## All methods

| Method | Endpoint |
|---|---|
| `$teepay->whoami()` | `GET /v1/whoami` |
| `$teepay->capabilities()` | `GET /v1/capabilities` |
| `$teepay->collections->create(array $params, array $opts = [])` | `POST /v1/collections` |
| `$teepay->collections->retrieve(string $reference)` | `GET /v1/collections/{reference}` |
| `$teepay->collections->list(['limit', 'cursor', 'status', 'currency'])` | `GET /v1/collections` |
| `$teepay->collections->listAll([...])` | all pages (Generator) |
| `$teepay->checkoutSessions->create(array $params, array $opts = [])` | `POST /v1/checkout-sessions` |
| `$teepay->checkoutSessions->retrieve(string $id)` | `GET /v1/checkout-sessions/{id}` |
| `$teepay->checkoutSessions->list(['limit', 'cursor'])` / `listAll()` | `GET /v1/checkout-sessions` |
| `$teepay->controlNumbers->create(array $params, array $opts = [])` | `POST /v1/control-numbers` |
| `$teepay->controlNumbers->retrieve(string $id)` | `GET /v1/control-numbers/{id}` |
| `$teepay->controlNumbers->list(['cursor'])` / `listAll()` | `GET /v1/control-numbers` (items in `data`) |
| `$teepay->disbursements->quote(array $params)` | `POST /v1/disbursements/quote` |
| `$teepay->disbursements->create(['quote_id' => …, 'idempotency_key' => …])` | `POST /v1/disbursements` |
| `$teepay->disbursements->retrieve(string $reference)` | `GET /v1/disbursements/{reference}` |
| `$teepay->disbursements->list([...])` / `listAll()` | `GET /v1/disbursements` |
| `Webhook::verify($payload, $headers, $secret, $tolerance = 300)` | webhook signature check |

```php
foreach ($teepay->collections->listAll(['status' => 'succeeded']) as $collection) {
    echo $collection->public_reference, PHP_EOL;
}
```

## Security

- Keep API keys on your server. The SDK never logs the key and redacts it from `var_dump()`/`print_r()`.
- Requests always verify TLS certificates.
- Fulfil orders only from a verified webhook or a server-side read, never from a browser redirect.

## Development

```bash
composer install
composer test
```

The tests run against a local mock server (`php -S`) that replays responses from the public OpenAPI document,
and a contract test checks that every public API operation is covered.

## License

MIT. See [LICENSE](LICENSE).

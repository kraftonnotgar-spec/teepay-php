<?php

declare(strict_types=1);

namespace TeePay\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use TeePay\Exception\AccountRestrictedError;
use TeePay\Exception\ApiError;
use TeePay\Exception\AuthenticationError;
use TeePay\Exception\ConflictError;
use TeePay\Exception\ConnectionError;
use TeePay\Exception\NotFoundError;
use TeePay\Exception\PermissionError;
use TeePay\Exception\RateLimitError;
use TeePay\Exception\TeePayError;
use TeePay\Exception\TimeoutError;
use TeePay\Exception\ValidationError;
use TeePay\HttpClient\HttpClientInterface;
use TeePay\HttpClient\HttpResponse;
use TeePay\TeePay;

final class RetryAndErrorsTest extends TestCase
{
    private const COLLECTION = ['money' => ['amount_minor' => '500000', 'currency' => 'TZS'], 'customer' => ['country' => 'TZ', 'phone' => '+255712345678']];

    public function testGeneratedIdempotencyKeyIsReusedAcrossRetries(): void
    {
        $this->server->enqueue([['error' => 'unavailable'], ['error' => 'internal_error']]);
        $collection = $this->client()->collections->create(self::COLLECTION);
        $this->assertSame('TP8K2M4QZ1', $collection->public_reference);
        $requests = $this->server->requests();
        $this->assertCount(3, $requests);
        $keys = array_unique(array_map(fn ($r) => $r['headers']['idempotency-key'], $requests));
        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^sdk-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
        // Retry-After: 0 on the 503 is honoured; the 500 falls back to jittered backoff (<= 1s).
        $this->assertSame(0.0, (float) $this->sleeps[0]);
        $this->assertLessThanOrEqual(1.0, $this->sleeps[1]);
    }

    public function testEachCallGetsAFreshGeneratedKey(): void
    {
        $client = $this->client();
        $client->checkoutSessions->create(['title' => 'a', 'amount_minor' => '100', 'currency' => 'TZS']);
        $client->checkoutSessions->create(['title' => 'a', 'amount_minor' => '100', 'currency' => 'TZS']);
        $keys = array_map(fn ($r) => $r['headers']['idempotency-key'], $this->server->requests());
        $this->assertNotSame($keys[0], $keys[1]);
        $this->assertLessThanOrEqual(120, strlen($keys[0]));
    }

    public function testRateLimitIsRetriedHonouringRetryAfter(): void
    {
        $this->server->enqueue([['error' => 'rate_limited']]);
        $result = $this->client()->whoami();
        $this->assertSame('sandbox', $result->environment);
        $this->assertCount(2, $this->server->requests());
        $this->assertSame([1], array_map('intval', $this->sleeps));
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $this->server->enqueue([['error' => 'unavailable'], ['error' => 'unavailable'], ['error' => 'unavailable']]);
        try {
            $this->client()->checkoutSessions->retrieve('7c0e5d2a-1b3f-4c8e-9a6d-2f4b8e1c0d9a');
            $this->fail('expected ApiError');
        } catch (ApiError $error) {
            $this->assertSame(503, $error->status);
            $this->assertSame('unavailable', $error->errorCode);
            $this->assertSame(0, $error->retryAfter);
        }
        $this->assertCount(3, $this->server->requests());
    }

    public function testPerRequestMaxRetriesOverride(): void
    {
        $this->server->enqueue([['error' => 'unavailable'], ['error' => 'unavailable']]);
        $this->expectException(ApiError::class);
        try {
            $this->client()->whoami(['max_retries' => 0]);
        } finally {
            $this->assertCount(1, $this->server->requests());
        }
    }

    public function testQuoteWithoutKeyIsNotRetriedOn5xx(): void
    {
        $this->server->enqueue([['error' => 'unavailable']]);
        try {
            $this->client()->disbursements->quote(['money' => ['amount' => 1000, 'currency' => 'TZS'], 'destination' => ['channel' => 'mobile', 'name' => 'A', 'phone' => '255712345678'], 'narration' => 'x']);
            $this->fail('expected ApiError');
        } catch (ApiError $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertCount(1, $this->server->requests());
    }

    public function testQuoteWithoutKeyIsRetriedOn429(): void
    {
        $this->server->enqueue([['error' => 'rate_limited']]);
        $quote = $this->client()->disbursements->quote(['money' => ['amount' => 1000, 'currency' => 'TZS'], 'destination' => ['channel' => 'mobile', 'name' => 'A', 'phone' => '255712345678'], 'narration' => 'x']);
        $this->assertSame('q_4f2c8a1b', $quote->id);
        $this->assertCount(2, $this->server->requests());
    }

    public function testDisbursementCreateRetriesWithSameBodyKey(): void
    {
        $this->server->enqueue([['error' => 'internal_error']]);
        $this->client()->disbursements->create(['quote_id' => 'q_4f2c8a1b']);
        $bodies = array_column($this->server->requests(), 'body');
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
    }

    public function testClientErrorsAreNeverRetried(): void
    {
        $this->server->enqueue([['error' => 'invalid_request']]);
        $this->expectException(ValidationError::class);
        try {
            $this->client()->collections->create(self::COLLECTION);
        } finally {
            $this->assertCount(1, $this->server->requests());
        }
    }

    public function testLongRetryAfterIsNotWaited(): void
    {
        $this->server->enqueue([['status' => 429, 'body' => ['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'request_id' => 'r1']], 'headers' => ['Retry-After' => '3600']]]);
        try {
            $this->client()->whoami();
            $this->fail('expected RateLimitError');
        } catch (RateLimitError $error) {
            $this->assertSame(3600, $error->retryAfter);
        }
        $this->assertSame([], $this->sleeps);
    }

    /**
     * @return array<string, array{string, class-string, int, string}>
     */
    public static function errorCases(): array
    {
        return [
            'invalid_credential' => ['invalid_credential', AuthenticationError::class, 401, 'req_401'],
            'scope_denied' => ['scope_denied', PermissionError::class, 403, 'req_403a'],
            'access_denied' => ['access_denied', PermissionError::class, 403, 'req_403b'],
            'account_restricted' => ['account_restricted', AccountRestrictedError::class, 403, 'req_403c'],
            'not_found' => ['not_found', NotFoundError::class, 404, 'req_404'],
            'idempotency_conflict' => ['idempotency_conflict', ConflictError::class, 409, 'req_409'],
            'invalid_request' => ['invalid_request', ValidationError::class, 422, 'req_422'],
            'unsupported_market' => ['unsupported_market', ValidationError::class, 422, 'req_422m'],
        ];
    }

    #[DataProvider('errorCases')]
    public function testErrorMapping(string $fixture, string $class, int $status, string $requestId): void
    {
        $this->server->enqueue([['error' => $fixture]]);
        try {
            $this->client(['max_retries' => 0])->collections->retrieve('TP8K2M4QZ1');
            $this->fail('expected ' . $class);
        } catch (ApiError $error) {
            $this->assertSame($class, $error::class);
            $this->assertInstanceOf(TeePayError::class, $error);
            $this->assertSame($status, $error->status);
            $this->assertSame($status, $error->getCode());
            $this->assertSame($fixture, $error->errorCode);
            $this->assertSame($fixture, $error->getErrorCode());
            $this->assertSame($requestId, $error->requestId);
            $this->assertSame(self::fixtures()['errors'][$fixture]['body']['error']['message'], $error->getMessage());
            $this->assertStringNotContainsString('sdk-test-secret', $error->getMessage() . json_encode($error->body));
        }
    }

    public function testAccountRestrictedIsAPermissionError(): void
    {
        $this->server->enqueue([['error' => 'account_restricted']]);
        $this->expectException(PermissionError::class);
        $this->client()->checkoutSessions->create(['title' => 'x', 'amount_minor' => '100', 'currency' => 'TZS']);
    }

    public function testRateLimitErrorAfterRetriesCarriesRetryAfter(): void
    {
        $this->server->enqueue([['error' => 'rate_limited'], ['error' => 'rate_limited'], ['error' => 'rate_limited']]);
        try {
            $this->client()->capabilities();
            $this->fail('expected RateLimitError');
        } catch (RateLimitError $error) {
            $this->assertSame(429, $error->status);
            $this->assertSame(1, $error->retryAfter);
            $this->assertSame('req_429', $error->requestId);
            $this->assertSame('0', $error->headers['ratelimit-remaining']);
        }
    }

    public function testCodeOnlyPayoutErrorGetsDefaultMessageAndHeaderRequestId(): void
    {
        $this->server->enqueue([['error' => 'payout_code_only']]);
        try {
            $this->client()->disbursements->create(['quote_id' => 'q_old', 'idempotency_key' => 'payout-0003']);
            $this->fail('expected ConflictError');
        } catch (ConflictError $error) {
            $this->assertSame('payout_quote_expired_or_changed', $error->errorCode);
            $this->assertStringContainsString('payout_quote_expired_or_changed', $error->getMessage());
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $error->requestId);
        }
    }

    public function testStringErrorShape(): void
    {
        $this->server->enqueue([['error' => 'payout_string_error']]);
        try {
            $this->client()->disbursements->quote(['money' => ['amount' => 1, 'currency' => 'TZS'], 'destination' => ['channel' => 'bank', 'name' => 'A', 'bank' => 'CRDB', 'account' => '123'], 'narration' => 'x']);
            $this->fail('expected ValidationError');
        } catch (ValidationError $error) {
            $this->assertSame('invalid_request', $error->errorCode);
            $this->assertSame(422, $error->status);
        }
    }

    public function testFundsUnderReviewWithoutRequestId(): void
    {
        $this->server->enqueue([['error' => 'funds_under_review']]);
        try {
            $this->client()->disbursements->create(['quote_id' => 'q', 'idempotency_key' => 'payout-0004']);
            $this->fail('expected ValidationError');
        } catch (ValidationError $error) {
            $this->assertSame('funds_under_review', $error->errorCode);
            $this->assertSame('Some of your balance is under review.', $error->getMessage());
        }
    }

    public function testNonJsonErrorBody(): void
    {
        $this->server->enqueue([['status' => 502, 'raw' => '<html>Bad gateway</html>'], ['status' => 502, 'raw' => '<html>Bad gateway</html>'], ['status' => 502, 'raw' => '<html>Bad gateway</html>']]);
        try {
            $this->client()->whoami();
            $this->fail('expected ApiError');
        } catch (ApiError $error) {
            $this->assertSame(ApiError::class, $error::class);
            $this->assertSame(502, $error->status);
            $this->assertNull($error->errorCode);
        }
    }

    public function testInvalidJsonSuccessBody(): void
    {
        $this->server->enqueue([['status' => 200, 'raw' => 'not json']]);
        $this->expectException(ApiError::class);
        $this->client()->whoami();
    }

    public function testTimeoutRaisesTimeoutError(): void
    {
        $this->server->enqueue([['delay_ms' => 1500, 'status' => 200, 'body' => ['ok' => true]]]);
        try {
            $this->client(['timeout' => 0.3, 'max_retries' => 0])->whoami();
            $this->fail('expected TimeoutError');
        } catch (TimeoutError $error) {
            $this->assertInstanceOf(ConnectionError::class, $error);
        }
        usleep(1_400_000); // let the single-threaded mock finish the delayed response
    }

    public function testConnectionErrorIsRetriedForSafeRequestsOnly(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket); // nothing listens here now
        $sleeps = [];
        $client = new TeePay(['api_key' => self::KEY, 'base_url' => 'http://' . $name, 'timeout' => 2, 'sleeper' => function ($s) use (&$sleeps) {
            $sleeps[] = $s;
        }]);
        try {
            $client->whoami();
            $this->fail('expected ConnectionError');
        } catch (ConnectionError $error) {
            $this->assertStringNotContainsString('sdk-test-secret', $error->getMessage());
        }
        $this->assertCount(2, $sleeps);

        $sleeps = [];
        try {
            $client->disbursements->quote(['money' => ['amount' => 1, 'currency' => 'TZS'], 'destination' => ['channel' => 'mobile', 'name' => 'A', 'phone' => '255712345678'], 'narration' => 'x']);
            $this->fail('expected ConnectionError');
        } catch (ConnectionError) {
        }
        $this->assertSame([], $sleeps);
    }

    public function testCustomHttpClientIsUsed(): void
    {
        $transport = new class () implements HttpClientInterface {
            /** @var list<array{string, string, array<string, string>, ?string, float}> */
            public array $calls = [];

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                $this->calls[] = [$method, $url, $headers, $body, $timeout];

                return new HttpResponse(200, ['x-request-id' => 'abc'], '{"application_reference":"app","environment":"live","scopes":["integration:read"],"credential_prefix":"tp_live_00000000000000000000000000000000"}');
            }
        };
        $client = new TeePay(['api_key' => self::LIVE_KEY, 'http_client' => $transport, 'timeout' => 12]);
        $result = $client->whoami(['timeout' => 3]);
        $this->assertSame('abc', $result->getLastResponse()->requestId);
        $this->assertSame('https://api.teepay.me/v1/whoami', $transport->calls[0][1]);
        $this->assertSame(3.0, $transport->calls[0][4]);
    }
}

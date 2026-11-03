<?php

declare(strict_types=1);

namespace TeePay\Tests;

use TeePay\Exception\WebhookVerificationError;
use TeePay\Webhook;

final class WebhookTest extends \PHPUnit\Framework\TestCase
{
    /** @var array{secret: string, timestamp: int, body: string, signature: string} */
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/fixtures.json'), true)['webhook'];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-TeePay-Event-ID' => '0f8d7c2e-5b1a-5e3f-9c4d-7a6b2e1f0c9d',
            'X-TeePay-Timestamp' => (string) $this->fixture['timestamp'],
            'X-TeePay-Signature' => $this->fixture['signature'],
        ];
    }

    public function testSignMatchesFixture(): void
    {
        $this->assertSame($this->fixture['signature'], Webhook::sign($this->fixture['body'], $this->fixture['secret'], $this->fixture['timestamp']));
    }

    public function testVerifiesValidDelivery(): void
    {
        $event = Webhook::verify($this->fixture['body'], $this->headers(), $this->fixture['secret'], 300, $this->fixture['timestamp'] + 10);
        $this->assertSame('payment.completed', $event->event);
        $this->assertSame('TP8K2M4QZ1', $event->data->reference);
        $this->assertSame('500000', $event['data']['money']['amount_minor']);
        $this->assertSame('checkout_session', $event->data->source->type);
    }

    public function testInstanceHelperAndGeneratedHeaders(): void
    {
        $client = new \TeePay\TeePay('tp_test_00000000000000000000000000000000.sdk-test-secret');
        $headers = $client->webhooks->generateTestHeaders($this->fixture['body'], $this->fixture['secret'], null, 'evt-1');
        $this->assertSame('evt-1', $headers['X-TeePay-Event-ID']);
        $event = $client->webhooks->verify($this->fixture['body'], $headers, $this->fixture['secret']);
        $this->assertSame('live', $event->environment);
        $this->assertSame($this->fixture['signature'], $client->webhooks->sign($this->fixture['body'], $this->fixture['secret'], $this->fixture['timestamp']));
    }

    public function testHeaderLookupIsCaseInsensitiveAndAcceptsServerAndPsr7Shapes(): void
    {
        $now = $this->fixture['timestamp'];
        $lower = array_change_key_case($this->headers(), CASE_LOWER);
        $this->assertTrue(Webhook::isValid($this->fixture['body'], $lower, $this->fixture['secret'], 300, $now));
        $server = ['HTTP_X_TEEPAY_TIMESTAMP' => (string) $now, 'HTTP_X_TEEPAY_SIGNATURE' => $this->fixture['signature'], 'REQUEST_METHOD' => 'POST'];
        $this->assertTrue(Webhook::isValid($this->fixture['body'], $server, $this->fixture['secret'], 300, $now));
        $psr7 = ['X-TeePay-Timestamp' => [(string) $now], 'X-TeePay-Signature' => [$this->fixture['signature']]];
        $this->assertTrue(Webhook::isValid($this->fixture['body'], $psr7, $this->fixture['secret'], 300, $now));
    }

    public function testAcceptsAnyMatchingSignatureInAList(): void
    {
        $headers = $this->headers();
        $headers['X-TeePay-Signature'] = 'v1=' . str_repeat('0', 64) . ', ' . $this->fixture['signature'];
        $this->assertTrue(Webhook::isValid($this->fixture['body'], $headers, $this->fixture['secret'], 300, $this->fixture['timestamp']));
    }

    /**
     * @return array<string, array{callable(array<string, string>, string, string): array{array<string, string>, string, string}, string}>
     */
    public static function invalidCases(): array
    {
        return [
            'tampered body' => [fn ($h, $b, $s) => [$h, str_replace('500000', '900000', $b), $s], 'does not match'],
            'wrong secret' => [fn ($h, $b, $s) => [$h, $b, $s . 'x'], 'does not match'],
            'missing signature' => [fn ($h, $b, $s) => [array_diff_key($h, ['X-TeePay-Signature' => 1]), $b, $s], 'Missing'],
            'missing timestamp' => [fn ($h, $b, $s) => [array_diff_key($h, ['X-TeePay-Timestamp' => 1]), $b, $s], 'Missing'],
            'non-numeric timestamp' => [fn ($h, $b, $s) => [['X-TeePay-Timestamp' => '17616x'] + $h, $b, $s], 'Invalid'],
            'other scheme' => [fn ($h, $b, $s) => [['X-TeePay-Signature' => 'v0=' . substr($h['X-TeePay-Signature'], 3)] + $h, $b, $s], 'does not match'],
            'uppercase hex' => [fn ($h, $b, $s) => [['X-TeePay-Signature' => strtoupper($h['X-TeePay-Signature'])] + $h, $b, $s], 'does not match'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCases')]
    public function testRejectsInvalidDeliveries(callable $mutate, string $message): void
    {
        [$headers, $body, $secret] = $mutate($this->headers(), $this->fixture['body'], $this->fixture['secret']);
        $this->expectException(WebhookVerificationError::class);
        $this->expectExceptionMessage($message);
        Webhook::verify($body, $headers, $secret, 300, $this->fixture['timestamp']);
    }

    public function testRejectsOldAndFutureTimestamps(): void
    {
        $t = $this->fixture['timestamp'];
        $this->assertFalse(Webhook::isValid($this->fixture['body'], $this->headers(), $this->fixture['secret'], 300, $t + 301));
        $this->assertFalse(Webhook::isValid($this->fixture['body'], $this->headers(), $this->fixture['secret'], 300, $t - 301));
        $this->assertTrue(Webhook::isValid($this->fixture['body'], $this->headers(), $this->fixture['secret'], 300, $t + 300));
        $this->assertTrue(Webhook::isValid($this->fixture['body'], $this->headers(), $this->fixture['secret'], 600, $t + 500));
        // The real clock: a 2026 fixture timestamp is far outside tolerance today or soon.
        $fresh = Webhook::generateTestHeaders($this->fixture['body'], $this->fixture['secret'], time() - 1000);
        $this->expectException(WebhookVerificationError::class);
        Webhook::verify($this->fixture['body'], $fresh, $this->fixture['secret']);
    }

    public function testRejectsDecodedPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('raw request body');
        Webhook::verify(json_decode($this->fixture['body'], true), $this->headers(), $this->fixture['secret'], 300, $this->fixture['timestamp']);
    }

    public function testRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Webhook::verify($this->fixture['body'], $this->headers(), '', 300, $this->fixture['timestamp']);
    }

    public function testRejectsSignedNonJsonBody(): void
    {
        $headers = Webhook::generateTestHeaders('not json', $this->fixture['secret']);
        $this->expectException(WebhookVerificationError::class);
        $this->expectExceptionMessage('not valid JSON');
        Webhook::verify('not json', $headers, $this->fixture['secret']);
    }
}

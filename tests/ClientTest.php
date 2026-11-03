<?php

declare(strict_types=1);

namespace TeePay\Tests;

use TeePay\Exception\TeePayError;
use TeePay\ResponseInfo;
use TeePay\TeePay;
use TeePay\TeePayObject;

final class ClientTest extends TestCase
{
    public function testStringKeyAndEnvironment(): void
    {
        $this->assertSame('sandbox', (new TeePay(self::KEY))->environment);
        $live = new TeePay(self::LIVE_KEY);
        $this->assertSame('live', $live->environment);
        $this->assertTrue($live->isLive());
    }

    public function testKeyFromEnvironmentVariable(): void
    {
        putenv('TEEPAY_API_KEY=' . self::LIVE_KEY);
        try {
            $this->assertSame('live', (new TeePay())->environment);
            $this->assertSame('live', (new TeePay(['base_url' => 'https://example.test']))->environment);
        } finally {
            putenv('TEEPAY_API_KEY');
        }
    }

    public function testMissingKeyThrows(): void
    {
        putenv('TEEPAY_API_KEY');
        $this->expectException(TeePayError::class);
        $this->expectExceptionMessage('TEEPAY_API_KEY');
        new TeePay();
    }

    public function testRejectsKeysWithoutTeePayPrefixWithoutEchoingThem(): void
    {
        try {
            new TeePay('sk_live_supersecretvalue');
            $this->fail('expected an exception');
        } catch (TeePayError $error) {
            $this->assertStringContainsString('tp_test_', $error->getMessage());
            $this->assertStringNotContainsString('supersecretvalue', $error->getMessage());
        }
    }

    public function testRejectsUnknownOptionsAndBadValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TeePay(['api_key' => self::KEY, 'apikey' => 'x']);
    }

    public function testRejectsBadBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TeePay(['api_key' => self::KEY, 'base_url' => 'ftp://example.test']);
    }

    public function testKeyIsRedactedFromDebugOutput(): void
    {
        $client = $this->client();
        $dump = print_r($client, true) . var_export($client->__debugInfo(), true);
        ob_start();
        var_dump($client);
        $dump .= ob_get_clean();
        $this->assertStringNotContainsString('sdk-test-secret', $dump);
        $this->assertStringContainsString('[redacted]', $dump);
    }

    public function testClientCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize($this->client());
    }

    public function testSendsBearerAuthAndStandardHeaders(): void
    {
        $this->client()->whoami();
        $request = $this->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/v1/whoami', $request['path']);
        $this->assertSame('Bearer ' . self::KEY, $request['headers']['authorization']);
        $this->assertArrayNotHasKey('x-api-key', $request['headers']);
        $this->assertSame('application/json', $request['headers']['accept']);
        $this->assertMatchesRegularExpression('#^teepay-php/0\.1\.0 php/8\.#', $request['headers']['user-agent']);
        $this->assertArrayNotHasKey('teepay-version', $request['headers']);
        $this->assertArrayNotHasKey('idempotency-key', $request['headers']);
        $this->assertSame('', $request['raw_query']);
    }

    public function testApiVersionHeaderOnlyWhenConfigured(): void
    {
        $this->client(['api_version' => TeePay::API_VERSION])->capabilities();
        $this->assertSame('2026-10-18', $this->lastRequest()['headers']['teepay-version']);
    }

    public function testWhoamiAndResponseMetadata(): void
    {
        $result = $this->client()->whoami();
        $this->assertInstanceOf(TeePayObject::class, $result);
        $this->assertSame('sandbox', $result['environment']);
        $this->assertSame('sandbox', $result->environment);
        $this->assertSame(['collection:read', 'collection:create'], $result->scopes);
        $info = $result->getLastResponse();
        $this->assertInstanceOf(ResponseInfo::class, $info);
        $this->assertSame(200, $info->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $info->requestId);
        $this->assertSame(['limit' => 120, 'remaining' => 119, 'reset' => 60], $info->rateLimit);
        $this->assertSame('2026-10-18', $info->apiVersion);
        $this->assertFalse($info->idempotentReplayed);
    }

    public function testCapabilities(): void
    {
        $caps = $this->client()->capabilities();
        $this->assertSame('/v1/capabilities', $this->lastRequest()['path']);
        $this->assertTrue($caps->features->collection_create);
        $this->assertSame(['TZ'], $caps['supported_countries']);
        $this->assertSame(['TZS'], $caps['supported_currencies']);
    }

    public function testObjectIsReadOnlyAndSerializable(): void
    {
        $result = $this->client()->whoami();
        $this->assertSame(json_encode(self::fixtures()['operations']['get-api-key']['body']), json_encode($result));
        $this->assertCount(4, $result);
        $this->assertSame(self::fixtures()['operations']['get-api-key']['body'], $result->toArray());
        $this->expectException(\LogicException::class);
        $result['environment'] = 'live';
    }
}

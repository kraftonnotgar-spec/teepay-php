<?php

declare(strict_types=1);

namespace TeePay\Tests;

use TeePay\TeePay;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    // Not a real credential: matches the documented format only.
    protected const KEY = 'tp_test_00000000000000000000000000000000.sdk-test-secret';
    protected const LIVE_KEY = 'tp_live_00000000000000000000000000000000.sdk-test-secret';

    protected MockServer $server;
    /** @var list<float> */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        $this->server = MockServer::get();
        $this->server->reset();
        $this->sleeps = [];
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function client(array $options = []): TeePay
    {
        return new TeePay($options + [
            'api_key' => self::KEY,
            'base_url' => $this->server->url,
            'timeout' => 5,
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fixtures(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/fixtures.json'), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastRequest(): array
    {
        $requests = $this->server->requests();
        $this->assertNotEmpty($requests, 'no request reached the mock server');

        return $requests[array_key_last($requests)];
    }
}

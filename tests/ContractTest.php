<?php

declare(strict_types=1);

namespace TeePay\Tests;

use TeePay\TeePay;

/**
 * Every public operation in the TeePay OpenAPI document must be reachable through the SDK,
 * and the SDK method must call exactly that HTTP method and path.
 */
final class ContractTest extends TestCase
{
    private const SAMPLE_ID = '7c0e5d2a-1b3f-4c8e-9a6d-2f4b8e1c0d9a';

    /**
     * Public operationId => [SDK method, invoker].
     *
     * @return array<string, array{string, callable(TeePay): mixed}>
     */
    private static function operations(): array
    {
        $collection = ['money' => ['amount_minor' => '500000', 'currency' => 'TZS'], 'customer' => ['country' => 'TZ', 'phone' => '+255712345678']];

        return [
            'get-api-key' => ['whoami', fn (TeePay $c) => $c->whoami()],
            'get-capabilities' => ['capabilities', fn (TeePay $c) => $c->capabilities()],
            'create-collection' => ['collections->create', fn (TeePay $c) => $c->collections->create($collection)],
            'list-collections' => ['collections->list', fn (TeePay $c) => $c->collections->list()],
            'get-collection' => ['collections->retrieve', fn (TeePay $c) => $c->collections->retrieve('TP8K2M4QZ1')],
            'create-payout-quote' => ['disbursements->quote', fn (TeePay $c) => $c->disbursements->quote(['money' => ['amount' => 2500000, 'currency' => 'TZS'], 'destination' => ['channel' => 'mobile', 'name' => 'Juma Ali', 'phone' => '255712345678'], 'narration' => 'Supplier payment'])],
            'create-payout' => ['disbursements->create', fn (TeePay $c) => $c->disbursements->create(['quote_id' => 'q_4f2c8a1b', 'idempotency_key' => 'payout-2026-10-0001'])],
            'list-payouts' => ['disbursements->list', fn (TeePay $c) => $c->disbursements->list()],
            'get-payout' => ['disbursements->retrieve', fn (TeePay $c) => $c->disbursements->retrieve('TPPO7Q2X9D')],
            'create-control-number' => ['controlNumbers->create', fn (TeePay $c) => $c->controlNumbers->create(['kind' => 'bill', 'customer' => ['name' => 'Asha'], 'amount_minor' => '5000000'])],
            'list-control-numbers' => ['controlNumbers->list', fn (TeePay $c) => $c->controlNumbers->list()],
            'get-control-number' => ['controlNumbers->retrieve', fn (TeePay $c) => $c->controlNumbers->retrieve('22222222-2222-4222-8222-222222222222')],
            'create-checkout-session' => ['checkoutSessions->create', fn (TeePay $c) => $c->checkoutSessions->create(['title' => 'Order 1001', 'amount_minor' => '500000', 'currency' => 'TZS'])],
            'list-checkout-sessions' => ['checkoutSessions->list', fn (TeePay $c) => $c->checkoutSessions->list()],
            'get-checkout-session' => ['checkoutSessions->retrieve', fn (TeePay $c) => $c->checkoutSessions->retrieve(self::SAMPLE_ID)],
        ];
    }

    /**
     * @return list<array{method: string, path: string, operationId: string, headerKey: bool}>
     */
    private static function specOperations(string $file): array
    {
        $spec = json_decode((string) file_get_contents($file), true);
        $result = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                $headerKey = false;
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    if (($parameter['in'] ?? '') === 'header' && strcasecmp($parameter['name'] ?? '', 'Idempotency-Key') === 0) {
                        $headerKey = true;
                    }
                }
                $result[] = ['method' => strtoupper($method), 'path' => $path, 'operationId' => $operation['operationId'], 'headerKey' => $headerKey];
            }
        }

        return $result;
    }

    public function testVendoredSpecHasOnlyPublicPaths(): void
    {
        $operations = self::specOperations(__DIR__ . '/fixtures/openapi.public.json');
        $this->assertCount(15, $operations);
        foreach ($operations as $operation) {
            $this->assertStringStartsWith('/v1/', $operation['path']);
        }
    }

    public function testEveryPublicOperationIsCoveredAndCallsTheRightRoute(): void
    {
        $map = self::operations();
        $spec = self::specOperations(__DIR__ . '/fixtures/openapi.public.json');
        $this->assertEqualsCanonicalizing(array_column($spec, 'operationId'), array_keys($map), 'SDK operation map and OpenAPI differ');

        foreach ($spec as $operation) {
            $this->server->reset();
            [$name, $invoke] = $map[$operation['operationId']];
            $result = $invoke($this->client());
            $this->assertNotNull($result, $name);
            $requests = $this->server->requests();
            $this->assertCount(1, $requests, $name);
            $pattern = '#^' . preg_replace('#\\\\\{[a-z_]+\\\\\}#', '[^/]+', preg_quote($operation['path'], '#')) . '$#';
            $this->assertSame($operation['method'], $requests[0]['method'], $name);
            $this->assertMatchesRegularExpression($pattern, $requests[0]['path'], $name);
            $this->assertSame($operation['headerKey'], isset($requests[0]['headers']['idempotency-key']), $name . ' Idempotency-Key header');
            $this->assertSame('Bearer ' . self::KEY, $requests[0]['headers']['authorization'], $name);
        }
    }

    public function testFixturesMatchVendoredSpec(): void
    {
        $fixtures = self::fixtures()['operations'];
        foreach (self::specOperations(__DIR__ . '/fixtures/openapi.public.json') as $operation) {
            $this->assertArrayHasKey($operation['operationId'], $fixtures);
            $this->assertSame([$operation['method'], $operation['path']], [$fixtures[$operation['operationId']]['method'], $fixtures[$operation['operationId']]['path']]);
        }
    }

    public function testVendoredSpecMatchesPublishedSpecWhenAvailable(): void
    {
        $source = getenv('TEEPAY_OPENAPI') ?: dirname(__DIR__, 3) . '/teepay/docs-site/openapi/teepay.json';
        if (!is_file($source)) {
            $this->markTestSkipped('Published TeePay OpenAPI document not available; set TEEPAY_OPENAPI to compare.');
        }
        $key = fn (array $o) => $o['method'] . ' ' . $o['path'] . ' ' . $o['operationId'];
        $this->assertEqualsCanonicalizing(
            array_map($key, self::specOperations($source)),
            array_map($key, self::specOperations(__DIR__ . '/fixtures/openapi.public.json')),
        );
    }
}

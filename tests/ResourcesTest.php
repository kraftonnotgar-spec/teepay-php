<?php

declare(strict_types=1);

namespace TeePay\Tests;

final class ResourcesTest extends TestCase
{
    private const SESSION_ID = '7c0e5d2a-1b3f-4c8e-9a6d-2f4b8e1c0d9a';
    private const NUMBER_ID = '22222222-2222-4222-8222-222222222222';

    public function testCreateCollection(): void
    {
        $collection = $this->client()->collections->create([
            'money' => ['amount_minor' => '500000', 'currency' => 'TZS'],
            'customer' => ['country' => 'TZ', 'phone' => '+255712345678', 'name' => 'Asha Juma', 'email' => null],
            'description' => 'Order 1001',
        ], ['idempotency_key' => 'order-1001']);

        $request = $this->lastRequest();
        $this->assertSame(['POST', '/v1/collections'], [$request['method'], $request['path']]);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('order-1001', $request['headers']['idempotency-key']);
        $this->assertSame('{"money":{"amount_minor":"500000","currency":"TZS"},"customer":{"country":"TZ","phone":"+255712345678","name":"Asha Juma"},"description":"Order 1001"}', $request['body']);
        $this->assertSame('TP8K2M4QZ1', $collection->public_reference);
        $this->assertSame('pending', $collection['status']);
        $this->assertSame(500000, $collection->money->amount_minor);
        $this->assertSame(202, $collection->getLastResponse()->status);
    }

    public function testIntegerAmountsBecomeStrings(): void
    {
        $client = $this->client();
        $client->collections->create(['money' => ['amount_minor' => 500000, 'currency' => 'TZS'], 'customer' => ['country' => 'TZ', 'phone' => '+255712345678']]);
        $this->assertSame('500000', json_decode($this->lastRequest()['body'], true)['money']['amount_minor']);
        $client->checkoutSessions->create(['title' => 'Order', 'amount_minor' => 250000, 'currency' => 'TZS']);
        $this->assertSame('250000', json_decode($this->lastRequest()['body'], true)['amount_minor']);
        $client->controlNumbers->create(['kind' => 'customer', 'customer' => ['name' => 'Asha'], 'amount_minor' => 1000, 'maximum_minor' => 9000]);
        $body = json_decode($this->lastRequest()['body'], true);
        $this->assertSame(['1000', '9000'], [$body['amount_minor'], $body['maximum_minor']]);
    }

    public function testFloatAndNonPositiveAmountsAreRejectedLocally(): void
    {
        $client = $this->client();
        foreach ([5000.0, 0, -5] as $amount) {
            try {
                $client->checkoutSessions->create(['title' => 'x', 'amount_minor' => $amount, 'currency' => 'TZS']);
                $this->fail('expected rejection of ' . var_export($amount, true));
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('amount_minor', $error->getMessage());
            }
        }
        $this->assertSame([], $this->server->requests());
    }

    public function testUppercaseKeysAreRejectedBeforeSending(): void
    {
        try {
            $this->client()->checkoutSessions->create(['title' => 'x', 'amount_minor' => '100', 'currency' => 'TZS', 'metadata' => ['OrderId' => '1']]);
            $this->fail('expected rejection');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('metadata.OrderId', $error->getMessage());
        }
        $this->assertSame([], $this->server->requests());
    }

    public function testRetrieveAndListCollections(): void
    {
        $client = $this->client();
        $collection = $client->collections->retrieve('TP8K2M4QZ1');
        $this->assertSame('/v1/collections/TP8K2M4QZ1', $this->lastRequest()['path']);
        $this->assertSame('succeeded', $collection->status);

        $page = $client->collections->list(['limit' => 50, 'status' => 'succeeded', 'currency' => 'TZS', 'cursor' => null, 'bogus' => 'x']);
        $request = $this->lastRequest();
        $this->assertSame('/v1/collections', $request['path']);
        $this->assertSame('limit=50&status=succeeded&currency=TZS', $request['raw_query']);
        $this->assertCount(1, $page['items']);
        $this->assertNull($page['next_cursor']);
    }

    public function testPathParametersAreEncodedAndRequired(): void
    {
        $client = $this->client();
        $client->collections->retrieve('a/b?c');
        $this->assertSame('/v1/collections/a%2Fb%3Fc', $this->lastRequest()['raw_path']);
        $this->expectException(\InvalidArgumentException::class);
        $client->disbursements->retrieve('');
    }

    public function testDisbursementQuoteCreateRetrieveList(): void
    {
        $client = $this->client();
        $quote = $client->disbursements->quote([
            'money' => ['amount' => 2500000, 'currency' => 'TZS'],
            'destination' => ['channel' => 'mobile', 'name' => 'Juma Ali', 'phone' => '255712345678'],
            'narration' => 'Supplier payment October',
        ]);
        $request = $this->lastRequest();
        $this->assertSame(['POST', '/v1/disbursements/quote'], [$request['method'], $request['path']]);
        $this->assertArrayNotHasKey('idempotency-key', $request['headers']);
        $this->assertSame(2500000, json_decode($request['body'], true)['money']['amount']);
        $this->assertSame('2550000', $quote->total_minor);

        $payout = $client->disbursements->create(['quote_id' => $quote->id, 'idempotency_key' => 'payout-2026-10-0001']);
        $request = $this->lastRequest();
        $this->assertSame(['POST', '/v1/disbursements'], [$request['method'], $request['path']]);
        $this->assertArrayNotHasKey('idempotency-key', $request['headers']);
        $this->assertSame(['quote_id' => 'q_4f2c8a1b', 'idempotency_key' => 'payout-2026-10-0001'], json_decode($request['body'], true));
        $this->assertSame('processing', $payout->status);

        $client->disbursements->retrieve('TPPO7Q2X9D');
        $this->assertSame('/v1/disbursements/TPPO7Q2X9D', $this->lastRequest()['path']);
        $page = $client->disbursements->list(['limit' => 10]);
        $this->assertSame('limit=10', $this->lastRequest()['raw_query']);
        $this->assertSame('payout', $page['items'][0]['operation']);
    }

    public function testDisbursementKeyFromOptionsOrGenerated(): void
    {
        $client = $this->client();
        $client->disbursements->create(['quote_id' => 'q_1'], ['idempotency_key' => 'payout-0002']);
        $this->assertSame('payout-0002', json_decode($this->lastRequest()['body'], true)['idempotency_key']);
        $client->disbursements->create(['quote_id' => 'q_1']);
        $this->assertMatchesRegularExpression('/^sdk-[0-9a-f-]{36}$/', json_decode($this->lastRequest()['body'], true)['idempotency_key']);
    }

    public function testControlNumbers(): void
    {
        $client = $this->client();
        $number = $client->controlNumbers->create([
            'kind' => 'bill',
            'customer' => ['name' => 'Asha Juma', 'phone' => '0712345678'],
            'description' => 'School fees, term 1',
            'amount_minor' => '5000000',
        ], ['idempotency_key' => 'invoice-77']);
        $request = $this->lastRequest();
        $this->assertSame(['POST', '/v1/control-numbers', 'invoice-77'], [$request['method'], $request['path'], $request['headers']['idempotency-key']]);
        $this->assertSame('991234567890', $number->number);
        $this->assertSame(201, $number->getLastResponse()->status);

        $one = $client->controlNumbers->retrieve(self::NUMBER_ID);
        $this->assertSame('/v1/control-numbers/' . self::NUMBER_ID, $this->lastRequest()['path']);
        $this->assertSame('verified', $one['payments'][0]['status']);

        $page = $client->controlNumbers->list(['cursor' => self::NUMBER_ID]);
        $this->assertSame('cursor=' . self::NUMBER_ID, $this->lastRequest()['raw_query']);
        $this->assertSame('list', $page->object);
        $this->assertCount(1, $page['data']);
    }

    public function testEmptyCustomerIsSentAsObject(): void
    {
        $this->client()->controlNumbers->create(['kind' => 'customer', 'customer' => []]);
        $this->assertSame('{"kind":"customer","customer":{}}', $this->lastRequest()['body']);
    }

    public function testControlNumberPendingIs202WithNullNumber(): void
    {
        $this->server->enqueue([['status' => 202, 'body' => ['id' => self::NUMBER_ID, 'object' => 'control_number', 'status' => 'pending', 'number' => null, 'currency' => 'TZS']]]);
        $number = $this->client()->controlNumbers->create(['kind' => 'bill', 'customer' => ['name' => 'A'], 'amount_minor' => '100'], ['idempotency_key' => 'invoice-78']);
        $this->assertSame(202, $number->getLastResponse()->status);
        $this->assertNull($number->number);
    }

    public function testCheckoutSessions(): void
    {
        $client = $this->client();
        $session = $client->checkoutSessions->create([
            'title' => 'Order 1001',
            'description' => '2 x Coffee bag',
            'amount_minor' => '500000',
            'currency' => 'TZS',
            'expires_in' => 86400,
            'reference' => 'order-1001',
            'metadata' => ['cart' => 'c-77'],
        ], ['idempotency_key' => 'order-1001']);
        $request = $this->lastRequest();
        $this->assertSame(['POST', '/v1/checkout-sessions', 'order-1001'], [$request['method'], $request['path'], $request['headers']['idempotency-key']]);
        $this->assertSame(86400, json_decode($request['body'], true)['expires_in']);
        $this->assertSame('https://pay.teepay.me/c/' . self::SESSION_ID, $session->checkout_url);
        $this->assertSame('open', $session->status);
        $this->assertNull($session->payment);

        $read = $client->checkoutSessions->retrieve(self::SESSION_ID);
        $this->assertSame('/v1/checkout-sessions/' . self::SESSION_ID, $this->lastRequest()['path']);
        $this->assertSame('TP8K2M4QZ1', $read->payment->reference);

        $page = $client->checkoutSessions->list(['limit' => 20]);
        $this->assertSame('limit=20', $this->lastRequest()['raw_query']);
        $this->assertSame('paid', $page['items'][0]['status']);
    }

    public function testIdempotentReplayHeader(): void
    {
        $body = self::fixtures()['operations']['create-checkout-session']['body'];
        $this->server->enqueue([['status' => 201, 'body' => $body, 'headers' => ['Idempotent-Replayed' => 'true']]]);
        $session = $this->client()->checkoutSessions->create(['title' => 'Order 1001', 'amount_minor' => '500000', 'currency' => 'TZS'], ['idempotency_key' => 'order-1001']);
        $this->assertTrue($session->getLastResponse()->idempotentReplayed);
    }

    public function testListAllFollowsCursors(): void
    {
        $item = self::fixtures()['operations']['list-checkout-sessions']['body']['items'][0];
        $this->server->enqueue([
            ['status' => 200, 'body' => ['object' => 'list', 'items' => [$item, $item], 'next_cursor' => 'page2']],
            ['status' => 200, 'body' => ['object' => 'list', 'items' => [$item], 'next_cursor' => null]],
        ]);
        $items = iterator_to_array($this->client()->checkoutSessions->listAll(['limit' => 2]), false);
        $this->assertCount(3, $items);
        $this->assertSame('checkout_session', $items[0]->object);
        $requests = $this->server->requests();
        $this->assertSame(['limit=2', 'limit=2&cursor=page2'], array_column($requests, 'raw_query'));
    }

    public function testListAllControlNumbersUsesDataField(): void
    {
        $row = self::fixtures()['operations']['list-control-numbers']['body']['data'][0];
        $next = '33333333-3333-4333-8333-333333333333';
        $this->server->enqueue([
            ['status' => 200, 'body' => ['object' => 'list', 'data' => [$row], 'next_cursor' => $next, 'can_create' => true]],
            ['status' => 200, 'body' => ['object' => 'list', 'data' => [$row], 'next_cursor' => null, 'can_create' => true]],
        ]);
        $items = iterator_to_array($this->client()->controlNumbers->listAll(), false);
        $this->assertCount(2, $items);
        $this->assertSame(['', 'cursor=' . $next], array_column($this->server->requests(), 'raw_query'));
    }

    public function testListAllCollectionsAndPayoutsStopOnRepeatedCursor(): void
    {
        $item = self::fixtures()['operations']['list-collections']['body']['items'][0];
        $this->server->enqueue([
            ['status' => 200, 'body' => ['items' => [$item], 'next_cursor' => 'c1']],
            ['status' => 200, 'body' => ['items' => [$item], 'next_cursor' => 'c1']],
        ]);
        $this->assertCount(2, iterator_to_array($this->client()->collections->listAll(['status' => 'succeeded']), false));
        $this->assertCount(1, iterator_to_array($this->client()->disbursements->listAll(), false));
    }
}

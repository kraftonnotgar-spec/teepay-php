<?php

declare(strict_types=1);

namespace TeePay\Service;

use TeePay\TeePayObject;
use TeePay\Util;

/**
 * Mobile money push payments (a USSD prompt on the customer's phone) and collection reads.
 */
final class Collections extends AbstractService
{
    /**
     * Sends a payment prompt to the customer's phone. POST /v1/collections (scope collection:create).
     *
     * A 202 result means the request was accepted, not that the customer paid. Wait for the
     * payment.completed webhook or retrieve the collection by its public_reference.
     *
     * @param array{money: array{amount_minor: string|int, currency: string}, customer: array{country: string, phone: string, name?: string, email?: string}, description?: string} $params
     * @param array{idempotency_key?: string, timeout?: float|int, max_retries?: int} $opts
     */
    public function create(array $params, array $opts = []): TeePayObject
    {
        if (isset($params['money']) && is_array($params['money']) && array_key_exists('amount_minor', $params['money'])) {
            $params['money']['amount_minor'] = Util::minor($params['money']['amount_minor'], 'money.amount_minor');
        }

        return $this->requestor->request('POST', '/v1/collections', null, Util::prepareBody($params), self::key($opts), $opts);
    }

    /**
     * One collection by its public reference. GET /v1/collections/{reference} (scope collection:read).
     *
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function retrieve(string $reference, array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/collections/' . Util::pathParam($reference, 'reference'), null, null, null, $opts);
    }

    /**
     * One page of collections, newest first. GET /v1/collections (scope collection:read).
     *
     * @param array{limit?: int, cursor?: string, status?: string, currency?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function list(array $params = [], array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/collections', self::only($params), null, null, $opts);
    }

    /**
     * Every collection across all pages.
     *
     * @param array{limit?: int, status?: string, currency?: string} $params
     *
     * @return \Generator<int, TeePayObject>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->paginate('/v1/collections', self::only($params), 'items', $opts);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function only(array $params): array
    {
        return array_intersect_key($params, array_flip(['limit', 'cursor', 'status', 'currency']));
    }
}

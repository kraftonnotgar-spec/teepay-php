<?php

declare(strict_types=1);

namespace TeePay\Service;

use TeePay\TeePayObject;
use TeePay\Util;

/**
 * Tanzanian Control Numbers (TZS) and the verified payments received on them.
 */
final class ControlNumbers extends AbstractService
{
    /**
     * Issues a Control Number. POST /v1/control-numbers (scope control_number:create).
     *
     * Status 201 returns the number. Status 202 (getLastResponse()->status) means issuance is
     * pending and `number` is null: call create again with the SAME idempotency_key, never a new one.
     *
     * @param array{kind: string, customer: array{name?: string, phone?: string, email?: string}, description?: string, amount_minor?: string|int, maximum_minor?: string|int, payment_mode?: string} $params
     * @param array{idempotency_key?: string, timeout?: float|int, max_retries?: int} $opts
     */
    public function create(array $params, array $opts = []): TeePayObject
    {
        foreach (['amount_minor', 'maximum_minor'] as $field) {
            if (array_key_exists($field, $params)) {
                $params[$field] = Util::minor($params[$field], $field);
            }
        }

        return $this->requestor->request('POST', '/v1/control-numbers', null, Util::prepareBody($params), self::key($opts), $opts);
    }

    /**
     * One Control Number with its payments. GET /v1/control-numbers/{id} (scope control_number:read).
     * Only payments with status "verified" are money received.
     *
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function retrieve(string $id, array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/control-numbers/' . Util::pathParam($id, 'id'), null, null, null, $opts);
    }

    /**
     * One page of Control Numbers. The items are in the `data` field (not `items`).
     * GET /v1/control-numbers (scope control_number:read).
     *
     * @param array{cursor?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function list(array $params = [], array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/control-numbers', array_intersect_key($params, ['cursor' => true]), null, null, $opts);
    }

    /**
     * Every Control Number across all pages.
     *
     * @return \Generator<int, TeePayObject>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->paginate('/v1/control-numbers', array_intersect_key($params, ['cursor' => true]), 'data', $opts);
    }
}

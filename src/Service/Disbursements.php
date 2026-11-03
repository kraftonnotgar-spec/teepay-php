<?php

declare(strict_types=1);

namespace TeePay\Service;

use TeePay\TeePayObject;
use TeePay\Util;

/**
 * Quoted payouts (disbursements) from your TeePay balance to mobile wallets and banks.
 */
final class Disbursements extends AbstractService
{
    /**
     * Prices a payout. A quote never moves money and expires after two minutes.
     * POST /v1/disbursements/quote (scope disbursement:create, live and approved accounts only).
     *
     * @param array{money: array{amount: int, currency: string}, destination: array{channel: string, name: string, phone?: string, bank?: string, account?: string}, narration: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function quote(array $params, array $opts = []): TeePayObject
    {
        return $this->requestor->request('POST', '/v1/disbursements/quote', null, Util::prepareBody($params), null, $opts);
    }

    /**
     * Confirms a quote and sends the payout. POST /v1/disbursements (scope disbursement:create).
     *
     * The idempotency key travels in the body as idempotency_key. Derive it from your own payout
     * record; if omitted the SDK generates one. A 202 result means the outcome is uncertain:
     * retrieve the payout and never retry with a new key.
     *
     * @param array{quote_id: string, idempotency_key?: string} $params
     * @param array{idempotency_key?: string, timeout?: float|int, max_retries?: int} $opts
     */
    public function create(array $params, array $opts = []): TeePayObject
    {
        $key = $params['idempotency_key'] ?? null;
        $params['idempotency_key'] = is_string($key) && $key !== '' ? $key : self::key($opts);

        return $this->requestor->request('POST', '/v1/disbursements', null, Util::prepareBody($params), null, $opts, true);
    }

    /**
     * One payout by its TeePay reference. GET /v1/disbursements/{reference} (scope disbursement:read).
     *
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function retrieve(string $reference, array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/disbursements/' . Util::pathParam($reference, 'reference'), null, null, null, $opts);
    }

    /**
     * One page of payouts, newest first. GET /v1/disbursements (scope disbursement:read).
     *
     * @param array{limit?: int, cursor?: string, status?: string, currency?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function list(array $params = [], array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/disbursements', self::only($params), null, null, $opts);
    }

    /**
     * Every payout across all pages.
     *
     * @param array{limit?: int, status?: string, currency?: string} $params
     *
     * @return \Generator<int, TeePayObject>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->paginate('/v1/disbursements', self::only($params), 'items', $opts);
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

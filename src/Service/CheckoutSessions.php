<?php

declare(strict_types=1);

namespace TeePay\Service;

use TeePay\TeePayObject;
use TeePay\Util;

/**
 * TeePay hosted checkout pages created from your server.
 */
final class CheckoutSessions extends AbstractService
{
    /**
     * Creates a hosted checkout. Redirect the customer to `checkout_url`.
     * POST /v1/checkout-sessions (scope collection:create).
     *
     * Repeating the same idempotency key and body returns the same session;
     * getLastResponse()->idempotentReplayed is then true. Metadata keys must be lower-case.
     *
     * @param array{title: string, amount_minor: string|int, currency: string, description?: string, expires_in?: int, payment_profile_id?: string, reference?: string, metadata?: array<string, string>} $params
     * @param array{idempotency_key?: string, timeout?: float|int, max_retries?: int} $opts
     */
    public function create(array $params, array $opts = []): TeePayObject
    {
        if (array_key_exists('amount_minor', $params)) {
            $params['amount_minor'] = Util::minor($params['amount_minor'], 'amount_minor');
        }

        return $this->requestor->request('POST', '/v1/checkout-sessions', null, Util::prepareBody($params), self::key($opts), $opts);
    }

    /**
     * One checkout session. `status` is "paid" only after TeePay verified the payment; then
     * `payment.reference` is set. GET /v1/checkout-sessions/{id} (scope collection:read).
     *
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function retrieve(string $id, array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/checkout-sessions/' . Util::pathParam($id, 'id'), null, null, null, $opts);
    }

    /**
     * One page of checkout sessions, newest first. GET /v1/checkout-sessions (scope collection:read).
     *
     * @param array{limit?: int, cursor?: string} $params
     * @param array{timeout?: float|int, max_retries?: int} $opts
     */
    public function list(array $params = [], array $opts = []): TeePayObject
    {
        return $this->requestor->request('GET', '/v1/checkout-sessions', array_intersect_key($params, ['limit' => true, 'cursor' => true]), null, null, $opts);
    }

    /**
     * Every checkout session across all pages.
     *
     * @param array{limit?: int} $params
     *
     * @return \Generator<int, TeePayObject>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->paginate('/v1/checkout-sessions', array_intersect_key($params, ['limit' => true, 'cursor' => true]), 'items', $opts);
    }
}

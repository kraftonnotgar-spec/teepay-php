<?php

declare(strict_types=1);

namespace TeePay\Service;

use TeePay\ApiRequestor;
use TeePay\TeePayObject;

/**
 * @internal
 */
abstract class AbstractService
{
    public function __construct(protected readonly ApiRequestor $requestor)
    {
    }

    /**
     * Follows next_cursor and yields every item of every page.
     *
     * @param array<string, mixed> $params
     *
     * @return \Generator<int, TeePayObject>
     */
    protected function paginate(string $path, array $params, string $itemsField, array $opts): \Generator
    {
        $seen = [];
        while (true) {
            $page = $this->requestor->request('GET', $path, $params, null, null, $opts);
            foreach ($page[$itemsField] ?? [] as $item) {
                yield new TeePayObject(is_array($item) ? $item : []);
            }
            $cursor = $page['next_cursor'] ?? null;
            if (!is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                return;
            }
            $seen[$cursor] = true;
            $params['cursor'] = $cursor;
        }
    }

    /**
     * @param array{idempotency_key?: string} $opts
     */
    protected static function key(array $opts): string
    {
        $key = $opts['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : \TeePay\Util::idempotencyKey();
    }
}

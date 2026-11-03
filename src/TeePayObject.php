<?php

declare(strict_types=1);

namespace TeePay;

/**
 * A decoded API result. Field names are exactly the API's snake_case names.
 *
 * Read fields as an array (`$session['checkout_url']`) or as properties (`$session->checkout_url`).
 * Nested objects read as properties are returned as TeePayObject too; read through ArrayAccess
 * they are plain arrays. The object is read-only.
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class TeePayObject implements \ArrayAccess, \JsonSerializable, \IteratorAggregate, \Countable
{
    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(private readonly array $values, private readonly ?ResponseInfo $lastResponse = null)
    {
    }

    public function getLastResponse(): ?ResponseInfo
    {
        return $this->lastResponse;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function __get(string $name): mixed
    {
        $value = $this->values[$name] ?? null;
        if (is_array($value) && !array_is_list($value)) {
            return new self($value);
        }

        return $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException('TeePay results are read-only.');
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('TeePay results are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('TeePay results are read-only.');
    }

    public function jsonSerialize(): mixed
    {
        return $this->values === [] ? new \stdClass() : $this->values;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    public function count(): int
    {
        return count($this->values);
    }
}

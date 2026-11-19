<?php

declare(strict_types=1);

namespace Patterns\Decomposition\DomainDrivenDesign\Sales;

use InvalidArgumentException;

/**
 * A value object.
 *
 * It has no identity: two Money objects with the same amount and currency
 * are interchangeable. It is immutable: "changing" it produces a new one.
 * And it carries its own rules, so "add EUR to USD" is impossible
 * everywhere, not just where someone remembered to check.
 */
final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency,
    ) {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->cents * $quantity, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}

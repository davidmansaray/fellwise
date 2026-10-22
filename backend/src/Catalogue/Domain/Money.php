<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain;

use InvalidArgumentException;

/**
 * Money as integer minor units. Never floats.
 */
final readonly class Money
{
    public function __construct(public int $amountMinor, public string $currency = 'GBP')
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Invalid currency [{$currency}].");
        }
    }

    public static function gbp(int $amountMinor): self
    {
        return new self($amountMinor, 'GBP');
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    public function toArray(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }
}

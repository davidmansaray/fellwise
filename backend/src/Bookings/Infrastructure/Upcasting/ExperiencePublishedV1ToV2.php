<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Infrastructure\Upcasting;

use Fellwise\Shared\Application\Messaging\Upcaster;

/**
 * v1 (2025): `price` was a float in pounds. v2: integer minor units under
 * `price_display`. Rounding happens once, here, not in every consumer.
 */
final class ExperiencePublishedV1ToV2 implements Upcaster
{
    public function eventName(): string
    {
        return 'catalogue.experience.published';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function upcast(array $payload): array
    {
        $price = $payload['price'] ?? 0;
        unset($payload['price']);

        return [
            ...$payload,
            'guide_id' => $payload['guide_id'] ?? null,
            'price_display' => [
                'amount_minor' => (int) round(((float) (is_numeric($price) ? $price : 0)) * 100),
                'currency' => 'GBP',
            ],
        ];
    }
}

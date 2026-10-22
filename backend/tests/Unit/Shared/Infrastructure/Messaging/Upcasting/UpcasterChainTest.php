<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Messaging\Upcasting;

use DateTimeImmutable;
use Fellwise\Bookings\Infrastructure\Upcasting\ExperiencePublishedV1ToV2;
use Fellwise\Shared\Application\Messaging\Upcaster;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Upcasting\UpcasterChain;
use PHPUnit\Framework\TestCase;

final class UpcasterChainTest extends TestCase
{
    public function test_upcasts_a_v1_published_event_to_v2_and_reports_the_path(): void
    {
        $chain = new UpcasterChain([new ExperiencePublishedV1ToV2]);
        $v1 = $this->envelope('catalogue.experience.published', 1, ['experience_id' => 'exp-1', 'title' => 'Scafell', 'price' => 85.1]);

        [$latest, $path] = $chain->upcast($v1);

        $this->assertSame([1], $path);
        $this->assertSame(2, $latest->eventVersion);
        $this->assertSame('catalogue.experience.published.v2', $latest->routingKey());
        $this->assertSame(['amount_minor' => 8510, 'currency' => 'GBP'], $latest->payload['price_display']);
        $this->assertArrayNotHasKey('price', $latest->payload);
        $this->assertSame($v1->eventId, $latest->eventId, 'Upcasting must keep the event identity for inbox dedupe.');
        $this->assertSame($v1->aggregateVersion, $latest->aggregateVersion);
    }

    public function test_leaves_a_current_version_event_untouched(): void
    {
        $chain = new UpcasterChain([new ExperiencePublishedV1ToV2]);
        $v2 = $this->envelope('catalogue.experience.published', 2, ['price_display' => ['amount_minor' => 100, 'currency' => 'GBP']]);

        [$latest, $path] = $chain->upcast($v2);

        $this->assertSame([], $path);
        $this->assertSame($v2, $latest);
    }

    public function test_ignores_events_with_no_registered_upcaster(): void
    {
        $chain = new UpcasterChain([new ExperiencePublishedV1ToV2]);
        $unpublished = $this->envelope('catalogue.experience.unpublished', 1, ['reason' => 'x']);

        [$latest, $path] = $chain->upcast($unpublished);

        $this->assertSame([], $path);
        $this->assertSame($unpublished, $latest);
    }

    public function test_chains_consecutive_upcasters_in_version_order(): void
    {
        $chain = new UpcasterChain([
            $this->upcaster('demo.thing.happened', 2, fn (array $p): array => [...$p, 'steps' => [...$p['steps'], 'v2→v3']]),
            $this->upcaster('demo.thing.happened', 1, fn (array $p): array => [...$p, 'steps' => [...$p['steps'], 'v1→v2']]),
        ]);

        [$latest, $path] = $chain->upcast($this->envelope('demo.thing.happened', 1, ['steps' => []]));

        $this->assertSame([1, 2], $path);
        $this->assertSame(3, $latest->eventVersion);
        $this->assertSame(['v1→v2', 'v2→v3'], $latest->payload['steps']);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $transform
     */
    private function upcaster(string $eventName, int $fromVersion, callable $transform): Upcaster
    {
        return new readonly class($eventName, $fromVersion, $transform) implements Upcaster
        {
            /** @var callable(array<string, mixed>): array<string, mixed> */
            private mixed $transform;

            public function __construct(private string $name, private int $from, callable $transform)
            {
                $this->transform = $transform;
            }

            public function eventName(): string
            {
                return $this->name;
            }

            public function fromVersion(): int
            {
                return $this->from;
            }

            public function upcast(array $payload): array
            {
                return ($this->transform)($payload);
            }
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function envelope(string $eventName, int $version, array $payload): Envelope
    {
        return new Envelope(
            '01K74ZQ9M2H3J4K5M6N7P8Q9RA', $eventName, $version, new DateTimeImmutable('2025-06-01T06:00:00+00:00'),
            'experience', 'exp-1', 3, '01K74ZQ9M2H3J4K5M6N7P8Q9RC', null, 'fellwise-cms', $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\Messaging;

use DateTimeImmutable;
use Fellwise\Shared\Domain\Messaging\Envelope;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFields(): iterable
    {
        foreach (['event_id', 'event_name', 'event_version', 'occurred_at', 'aggregate_type', 'aggregate_id', 'correlation_id', 'producer', 'payload'] as $field) {
            yield $field => [$field];
        }
    }

    public function test_json_round_trip_preserves_every_field(): void
    {
        $envelope = $this->envelope();

        $decoded = Envelope::fromJson($envelope->toJson());

        $this->assertSame($envelope->toArray(), $decoded->toArray());
        $this->assertSame('2026-10-08T09:15:30.123+00:00', $decoded->toArray()['occurred_at']);
    }

    public function test_wire_format_has_exactly_the_documented_keys(): void
    {
        $this->assertSame(
            ['event_id', 'event_name', 'event_version', 'occurred_at', 'aggregate_type', 'aggregate_id', 'aggregate_version', 'correlation_id', 'causation_id', 'producer', 'payload'],
            array_keys($this->envelope()->toArray()),
        );
    }

    public function test_routing_key_is_the_versioned_event_name(): void
    {
        $this->assertSame('catalogue.experience.published.v2', $this->envelope()->routingKey());
    }

    public function test_json_does_not_escape_slashes(): void
    {
        $envelope = $this->envelope(payload: ['url' => 'https://fellwise.co.uk/walks']);

        $this->assertStringContainsString('"https://fellwise.co.uk/walks"', $envelope->toJson());
    }

    #[DataProvider('requiredFields')]
    public function test_rejects_an_envelope_missing_a_required_field(string $field): void
    {
        $data = $this->envelope()->toArray();
        unset($data[$field]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Envelope is missing [{$field}].");

        Envelope::fromArray($data);
    }

    public function test_rejects_a_scalar_payload(): void
    {
        $data = [...$this->envelope()->toArray(), 'payload' => 'not-an-object'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Envelope payload must be an object.');

        Envelope::fromArray($data);
    }

    public function test_rejects_json_that_is_not_an_object(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Envelope::fromJson('"just a string"');
    }

    public function test_rejects_malformed_json(): void
    {
        $this->expectException(JsonException::class);

        Envelope::fromJson('{"event_id": ');
    }

    public function test_tolerates_absent_optional_fields(): void
    {
        $data = $this->envelope()->toArray();
        unset($data['aggregate_version'], $data['causation_id']);

        $envelope = Envelope::fromArray($data);

        $this->assertSame(0, $envelope->aggregateVersion);
        $this->assertNull($envelope->causationId);
    }

    public function test_with_payload_changes_only_version_and_payload(): void
    {
        $original = $this->envelope();

        $upcast = $original->withPayload(3, ['shape' => 'new']);

        $this->assertSame(3, $upcast->eventVersion);
        $this->assertSame(['shape' => 'new'], $upcast->payload);
        $this->assertSame(
            array_diff_key($original->toArray(), ['event_version' => true, 'payload' => true]),
            array_diff_key($upcast->toArray(), ['event_version' => true, 'payload' => true]),
        );
        $this->assertSame(2, $original->eventVersion, 'The original envelope must stay immutable.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function envelope(array $payload = ['experience_id' => '01K74ZQ9M2H3J4K5M6N7P8Q9RS', 'title' => 'Helvellyn']): Envelope
    {
        return new Envelope(
            eventId: '01K74ZQ9M2H3J4K5M6N7P8Q9RA',
            eventName: 'catalogue.experience.published',
            eventVersion: 2,
            occurredAt: new DateTimeImmutable('2026-10-08T09:15:30.123+00:00'),
            aggregateType: 'experience',
            aggregateId: '01K74ZQ9M2H3J4K5M6N7P8Q9RS',
            aggregateVersion: 4,
            correlationId: '01K74ZQ9M2H3J4K5M6N7P8Q9RC',
            causationId: '01K74ZQ9M2H3J4K5M6N7P8Q9RD',
            producer: 'fellwise-cms',
            payload: $payload,
        );
    }
}

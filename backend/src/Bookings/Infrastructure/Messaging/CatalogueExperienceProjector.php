<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Infrastructure\Messaging;

use Fellwise\Shared\Application\Messaging\MessageHandler;
use Fellwise\Shared\Application\Messaging\UnprocessableMessage;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Illuminate\Database\ConnectionInterface;

/**
 * Builds Core's `catalogue_experiences` from Catalogue events. Writes are
 * guarded by aggregate version, so a late or replayed older event can never
 * overwrite newer state (RabbitMQ only orders per queue, and retries reorder).
 */
final readonly class CatalogueExperienceProjector implements MessageHandler
{
    /** @var list<string> listing content, as opposed to state (status/version) */
    private const array CONTENT = ['title', 'slug', 'region_id', 'price_minor', 'currency', 'source'];

    public function __construct(private ConnectionInterface $core) {}

    public function handle(Envelope $envelope, bool $isReplay): void
    {
        $payload = $envelope->payload;

        match ($envelope->eventName) {
            'catalogue.experience.published' => UnprocessableMessage::requireKeys($payload, $envelope->routingKey(), 'title', 'slug', 'region_id', 'price_display.amount_minor', 'price_display.currency'),
            'legacy.experience.imported' => UnprocessableMessage::requireKeys($payload, $envelope->routingKey(), 'title', 'slug', 'region_id', 'price.amount_minor', 'price.currency', 'live'),
            default => null,
        };

        match ($envelope->eventName) {
            'catalogue.experience.published' => $this->upsert($envelope, [
                'title' => (string) $payload['title'],
                'slug' => (string) $payload['slug'],
                'region_id' => (string) $payload['region_id'],
                'price_minor' => (int) $payload['price_display']['amount_minor'],
                'currency' => (string) $payload['price_display']['currency'],
                'status' => 'published',
                'source' => 'cms',
            ]),
            'legacy.experience.imported' => $this->upsert($envelope, [
                'title' => (string) $payload['title'],
                'slug' => (string) $payload['slug'],
                'region_id' => (string) $payload['region_id'],
                'price_minor' => (int) $payload['price']['amount_minor'],
                'currency' => (string) $payload['price']['currency'],
                'status' => $payload['live'] ? 'published' : 'unpublished',
                'source' => 'traildesk',
            ]),
            // Upsert a tombstone so an unpublish that overtakes its publish
            // (retries reorder messages) still wins; the late publish can then
            // fill in content but never resurrect the listing.
            'catalogue.experience.unpublished' => $this->upsert($envelope, [
                'title' => '',
                'slug' => '',
                'region_id' => '',
                'price_minor' => 0,
                'currency' => 'GBP',
                'status' => 'unpublished',
                'source' => 'cms',
            ]),
            default => null,
        };
    }

    /**
     * @param  array<string, string|int>  $fields
     */
    private function upsert(Envelope $envelope, array $fields): void
    {
        $columns = [
            'experience_id' => $envelope->aggregateId,
            ...$fields,
            'version' => $envelope->aggregateVersion,
            'last_event_id' => $envelope->eventId,
            'synced_at' => now()->format('Y-m-d H:i:s.u'),
        ];

        $newer = 'incoming.version > catalogue_experiences.version';
        // Content may also come from an older event when the row is a
        // tombstone (an unpublish arrived first), but never from an empty one.
        $contentWins = "({$newer} AND incoming.title <> '') OR (catalogue_experiences.title = '' AND incoming.title <> '')";

        $assignments = collect(array_keys($columns))
            ->reject(fn (string $column) => $column === 'experience_id')
            ->map(fn (string $column) => in_array($column, self::CONTENT, true)
                ? "{$column} = IF({$contentWins}, incoming.{$column}, catalogue_experiences.{$column})"
                : "{$column} = IF({$newer}, incoming.{$column}, catalogue_experiences.{$column})")
            // `version` must be assigned last so every guard above reads the old value.
            ->sortBy(fn (string $assignment) => str_starts_with($assignment, 'version =') ? 1 : 0)
            ->implode(', ');

        $this->core->statement(
            sprintf(
                'INSERT INTO catalogue_experiences (%s) VALUES (%s) AS incoming ON DUPLICATE KEY UPDATE %s',
                implode(', ', array_keys($columns)),
                implode(', ', array_fill(0, count($columns), '?')),
                $assignments,
            ),
            array_values($columns),
        );
    }
}

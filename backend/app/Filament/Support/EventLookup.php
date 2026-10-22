<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Read-side helpers for the console: finds an event in whichever context's
 * outbox produced it and gathers its delivery history from ops.
 */
final class EventLookup
{
    /**
     * @return array{context: string, envelope: Envelope, row: stdClass}|null
     */
    public static function find(string $eventId): ?array
    {
        foreach (['cms', 'core'] as $context) {
            $row = DB::connection($context)->table(OutboxTable::TABLE)->where('id', $eventId)->first();

            if ($row !== null) {
                return ['context' => $context, 'envelope' => OutboxTable::toEnvelope($row), 'row' => $row];
            }
        }

        return null;
    }

    /**
     * @return Collection<int, stdClass>
     */
    public static function attempts(string $eventId): Collection
    {
        return DB::connection('ops')->table('delivery_attempts')->where('event_id', $eventId)->orderBy('id')->get();
    }

    /**
     * Every event sharing a correlation id, across both outboxes, in causal order.
     *
     * @return Collection<int, array{context: string, envelope: Envelope}>
     */
    public static function correlated(string $correlationId): Collection
    {
        return collect(['cms', 'core'])
            ->flatMap(fn (string $context) => DB::connection($context)->table(OutboxTable::TABLE)
                ->where('correlation_id', $correlationId)->orderBy('id')->limit(50)->get()
                ->map(fn (stdClass $row) => ['context' => $context, 'envelope' => OutboxTable::toEnvelope($row)]))
            ->sortBy(fn (array $item) => $item['envelope']->eventId)
            ->values();
    }

    public static function highlightJson(mixed $value): string
    {
        $json = e((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return (string) preg_replace(
            ['/(&quot;[^&]*?&quot;)(\s*:)/', '/:\s(&quot;.*?&quot;)/', '/:\s(-?\d+(?:\.\d+)?|true|false|null)/'],
            ['<span class="k">$1</span>$2', ': <span class="s">$1</span>', ': <span class="n">$1</span>'],
            $json,
        );
    }

    /**
     * @return array{0: string, 1: string} [lane, colour] for a delivery outcome
     */
    public static function lane(string $outcome, string $consumer): array
    {
        return match ($outcome) {
            'published' => ['cms', 'info'],
            'replayed' => ['cms', 'info'],
            'retry_scheduled' => ['broker', 'warn'],
            'parked' => ['broker', 'bad'],
            'unparked' => ['broker', 'info'],
            'duplicate' => [$consumer === 'cms.bookings' ? 'cms' : 'core', 'info'],
            default => [$consumer === 'cms.bookings' ? 'cms' : 'core', 'ok'],
        };
    }
}

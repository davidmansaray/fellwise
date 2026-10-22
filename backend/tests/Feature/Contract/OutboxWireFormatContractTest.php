<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use Fellwise\Catalogue\Application\UnpublishExperience;
use Fellwise\Catalogue\Application\UnpublishExperienceHandler;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxTable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCatalogue;
use Tests\Concerns\RefreshContexts;
use Tests\Support\EventSchemas;
use Tests\TestCase;

/**
 * What actually leaves the outbox must satisfy the published schemas, not
 * just what the domain classes produce in isolation.
 */
final class OutboxWireFormatContractTest extends TestCase
{
    use InteractsWithCatalogue;
    use RefreshContexts;

    public function test_every_message_the_relay_publishes_matches_the_envelope_and_payload_schemas(): void
    {
        $id = $this->createPublishedExperience();
        $this->app->make(UnpublishExperienceHandler::class)(new UnpublishExperience($id, 'seasonal'));
        $this->broker();
        $this->app->make(Outboxes::class)->relay('cms')->relayBatch();

        $schemas = new EventSchemas;
        $messages = $this->broker()->confirmed();

        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $wire = json_decode($message['envelope']->toJson(), true);
            $this->assertSame([], $schemas->envelopeViolations($wire), 'envelope');
            $this->assertSame([], $schemas->payloadViolations($message['envelope']->routingKey(), $wire['payload']), $message['envelope']->routingKey());
        }
    }

    /**
     * The wire carries RFC 3339 with milliseconds, so the stable contract is
     * that decoding and re-encoding a message never changes its bytes.
     */
    public function test_the_wire_form_of_an_outbox_row_round_trips_byte_for_byte(): void
    {
        $this->createPublishedExperience();
        $row = DB::connection('cms')->table(OutboxTable::TABLE)->first();

        $wire = OutboxTable::toEnvelope($row)->toJson();

        $this->assertSame($row->id, Envelope::fromJson($wire)->eventId);
        $this->assertSame($wire, Envelope::fromJson($wire)->toJson());
    }
}

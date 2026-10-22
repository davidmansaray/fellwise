<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Fellwise\Catalogue\Application\PublishExperience;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Fellwise\Catalogue\Domain\Experience;
use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Catalogue\Domain\ExperienceStatus;
use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\MessageProcessor;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Fellwise\Shared\Infrastructure\Messaging\ProcessingResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\InMemoryMessagePublisher;

/**
 * Arrange helpers that drive the Catalogue through its real command
 * handlers and carry events across the "wire" with the in-memory broker.
 */
trait InteractsWithCatalogue
{
    private ?InMemoryMessagePublisher $broker = null;

    protected function createDraftExperience(string $title = 'Helvellyn via Striding Edge', int $priceMinor = 8500, string $regionId = 'lake-district'): string
    {
        $id = (string) Str::ulid();

        DB::connection('cms')->transaction(fn () => $this->app->make(ExperienceRepository::class)->save(new Experience(
            $id, $title, Str::slug($title), $regionId, null, Money::gbp($priceMinor), ExperienceStatus::Draft, 0,
        )));

        return $id;
    }

    protected function createPublishedExperience(string $title = 'Helvellyn via Striding Edge', int $priceMinor = 8500, string $regionId = 'lake-district'): string
    {
        $id = $this->createDraftExperience($title, $priceMinor, $regionId);
        ($this->app->make(PublishExperienceHandler::class))(new PublishExperience($id));

        return $id;
    }

    protected function broker(): InMemoryMessagePublisher
    {
        return $this->broker ??= $this->fakePublisher();
    }

    /**
     * Relay a context's outbox through the fake broker and feed every newly
     * confirmed message to a consumer queue, as `outbox:relay` + `messaging:consume` would.
     *
     * @return list<ProcessingResult>
     */
    protected function deliverOutbox(string $context, string $queue): array
    {
        $broker = $this->broker();
        $alreadyDelivered = count($broker->confirmed());
        $this->app->make(Outboxes::class)->relay($context)->relayBatch();

        return array_map(
            fn (array $message): ProcessingResult => $this->consume($queue, $message['envelope'], $message['headers']),
            array_slice($broker->confirmed(), $alreadyDelivered),
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    protected function consume(string $queue, Envelope $envelope, array $headers = []): ProcessingResult
    {
        return $this->app->make(MessageProcessor::class)->process($queue, $envelope->toJson(), $headers);
    }
}

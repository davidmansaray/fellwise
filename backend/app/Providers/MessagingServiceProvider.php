<?php

declare(strict_types=1);

namespace App\Providers;

use Fellwise\Bookings\Application\ConfirmBookingHandler;
use Fellwise\Bookings\Domain\BookingRepository;
use Fellwise\Bookings\Infrastructure\Messaging\CatalogueExperienceProjector;
use Fellwise\Bookings\Infrastructure\Persistence\DatabaseBookingRepository;
use Fellwise\Bookings\Infrastructure\Reporting\PerformanceReport;
use Fellwise\Bookings\Infrastructure\Upcasting\ExperiencePublishedV1ToV2;
use Fellwise\Catalogue\Application\ImportLegacyExperienceHandler;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Fellwise\Catalogue\Application\ReviseExperienceHandler;
use Fellwise\Catalogue\Application\UnpublishExperienceHandler;
use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Catalogue\Infrastructure\Messaging\BookingStatsProjector;
use Fellwise\Catalogue\Infrastructure\Persistence\EloquentExperienceRepository;
use Fellwise\Migration\Infrastructure\BackfillTrailDesk;
use Fellwise\Migration\Infrastructure\TrailDeskTranslator;
use Fellwise\Operations\ConsistencyChecker;
use Fellwise\Shared\Application\Messaging\CorrelationContext;
use Fellwise\Shared\Application\Messaging\EventRecorder;
use Fellwise\Shared\Application\UnitOfWork;
use Fellwise\Shared\Infrastructure\Messaging\MessageProcessor;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxEventRecorder;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\AmqpConnectionFactory;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\BrokerManagementClient;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\MessagePublisher;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\ParkingLot;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RabbitMessagePublisher;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RetryPolicy;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryLog;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Fellwise\Shared\Infrastructure\Messaging\Upcasting\UpcasterChain;
use Fellwise\Shared\Infrastructure\Persistence\DatabaseUnitOfWork;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root: binds each bounded context's ports to adapters on that
 * context's own database connection and outbox.
 */
final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CorrelationContext::class);

        $this->app->singleton(AmqpConnectionFactory::class, fn (Application $app) => new AmqpConnectionFactory([
            'host' => (string) config('messaging.connection.host'),
            'port' => (int) config('messaging.connection.port'),
            'user' => (string) config('messaging.connection.user'),
            'password' => (string) config('messaging.connection.password'),
            'vhost' => (string) config('messaging.connection.vhost'),
            'heartbeat' => (int) config('messaging.connection.heartbeat'),
        ]));

        $this->app->singleton(MessagePublisher::class, fn (Application $app) => new RabbitMessagePublisher(
            $app->make(AmqpConnectionFactory::class),
            (string) config('messaging.exchanges.events'),
        ));

        $this->app->singleton(DeliveryLog::class, fn (Application $app) => new DeliveryLog($this->connection('ops')));
        $this->app->singleton(FaultInjector::class, fn (Application $app) => new FaultInjector($this->connection('ops')));
        $this->app->singleton(UpcasterChain::class, fn () => new UpcasterChain([new ExperiencePublishedV1ToV2]));
        $this->app->singleton(RetryPolicy::class, fn () => new RetryPolicy(array_values((array) config('messaging.retry_tiers'))));

        $this->app->singleton(MessageProcessor::class, fn (Application $app) => new MessageProcessor(
            (array) config('messaging.consumers'),
            $app,
            $app->make(DatabaseManager::class),
            $app->make(UpcasterChain::class),
            $app->make(RetryPolicy::class),
            $app->make(DeliveryLog::class),
            $app->make(FaultInjector::class),
            $app->make(CorrelationContext::class),
        ));

        $this->app->bind(RabbitConsumer::class, fn (Application $app) => new RabbitConsumer(
            $app->make(AmqpConnectionFactory::class),
            $app->make(MessageProcessor::class),
            $app->make(MessagePublisher::class),
            (string) config('messaging.exchanges.retry'),
            (string) config('messaging.exchanges.parking'),
        ));

        $this->app->bind(ParkingLot::class, fn (Application $app) => new ParkingLot(
            $app->make(AmqpConnectionFactory::class),
            $app->make(MessagePublisher::class),
            $app->make(DeliveryLog::class),
            (string) config('messaging.exchanges.requeue'),
        ));

        $this->app->singleton(BrokerManagementClient::class, fn (Application $app) => new BrokerManagementClient(
            $app->make(HttpFactory::class),
            (string) config('messaging.connection.management_url'),
            (string) config('messaging.connection.user'),
            (string) config('messaging.connection.password'),
            (string) config('messaging.connection.vhost'),
        ));

        $this->app->singleton(Outboxes::class, fn (Application $app) => new Outboxes(
            (array) config('messaging.producers'),
            $app->make(DatabaseManager::class),
            $app->make(MessagePublisher::class),
            $app->make(DeliveryLog::class),
        ));

        $this->registerCatalogue();
        $this->registerBookings();
        $this->registerOperations();
    }

    private function registerCatalogue(): void
    {
        $this->app->bind(ExperienceRepository::class, EloquentExperienceRepository::class);

        $this->app->when(EloquentExperienceRepository::class)
            ->needs(EventRecorder::class)
            ->give(fn () => $this->recorderFor('cms'));

        $this->app->when([PublishExperienceHandler::class, UnpublishExperienceHandler::class, ReviseExperienceHandler::class, ImportLegacyExperienceHandler::class, BackfillTrailDesk::class])
            ->needs(UnitOfWork::class)
            ->give(fn () => new DatabaseUnitOfWork($this->connection('cms')));

        $this->app->when(BookingStatsProjector::class)
            ->needs(ConnectionInterface::class)
            ->give(fn () => $this->connection('cms'));

        $this->app->bind(BackfillTrailDesk::class, fn (Application $app) => new BackfillTrailDesk(
            $this->connection('legacy'),
            $this->connection('cms'),
            new DatabaseUnitOfWork($this->connection('cms')),
            new TrailDeskTranslator,
            $app->make(ImportLegacyExperienceHandler::class),
        ));
    }

    private function registerBookings(): void
    {
        $this->app->bind(BookingRepository::class, fn () => new DatabaseBookingRepository(
            $this->connection('core'),
            $this->recorderFor('core'),
        ));

        $this->app->when(ConfirmBookingHandler::class)
            ->needs(UnitOfWork::class)
            ->give(fn () => new DatabaseUnitOfWork($this->connection('core')));

        $this->app->when([CatalogueExperienceProjector::class, PerformanceReport::class])
            ->needs(ConnectionInterface::class)
            ->give(fn () => $this->connection('core'));
    }

    private function registerOperations(): void
    {
        $this->app->bind(ConsistencyChecker::class, fn () => new ConsistencyChecker(
            $this->connection('cms'),
            $this->connection('core'),
            $this->connection('ops'),
        ));
    }

    private function recorderFor(string $context): EventRecorder
    {
        return new OutboxEventRecorder(
            $this->connection($this->producerConnection($context)),
            $this->app->make(CorrelationContext::class),
            (string) config("messaging.producers.{$context}.producer"),
        );
    }

    private function producerConnection(string $context): string
    {
        return (string) config("messaging.producers.{$context}.connection");
    }

    private function connection(string $name): ConnectionInterface
    {
        return $this->app->make(DatabaseManager::class)->connection($name);
    }
}

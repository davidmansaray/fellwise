<?php

use Fellwise\Bookings\Infrastructure\Messaging\CatalogueExperienceProjector;
use Fellwise\Catalogue\Infrastructure\Messaging\BookingStatsProjector;

return [

    'connection' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', 'fellwise'),
        'heartbeat' => 30,
        'management_url' => env('RABBITMQ_MANAGEMENT_URL', 'http://127.0.0.1:15672'),
    ],

    /*
     * Topology is owned by infra/rabbitmq/definitions.json; the app only
     * refers to it by name and never declares exchanges or queues itself.
     */
    'exchanges' => [
        'events' => 'fellwise.events',
        'retry' => 'fellwise.retry',
        'requeue' => 'fellwise.requeue',
        'parking' => 'fellwise.parking',
    ],

    /*
     * Each bounded context publishes from its own outbox.
     */
    'producers' => [
        'cms' => ['connection' => 'cms', 'producer' => 'fellwise-cms'],
        'core' => ['connection' => 'core', 'producer' => 'fellwise-core'],
    ],

    /*
     * Retry tiers, in order. A failed message moves to the next tier; after
     * the last one it is parked. TTLs live on the queues in definitions.json.
     */
    'retry_tiers' => ['5s', '15s', '45s'],

    'consumers' => [
        'core.catalogue' => [
            'connection' => 'core',
            'prefetch' => 20,
            'handlers' => [
                'catalogue.experience.published' => CatalogueExperienceProjector::class,
                'catalogue.experience.unpublished' => CatalogueExperienceProjector::class,
                'legacy.experience.imported' => CatalogueExperienceProjector::class,
            ],
        ],
        'cms.bookings' => [
            'connection' => 'cms',
            'prefetch' => 50,
            'handlers' => [
                'bookings.booking.confirmed' => BookingStatsProjector::class,
                'bookings.availability.changed' => BookingStatsProjector::class,
            ],
        ],
    ],

];

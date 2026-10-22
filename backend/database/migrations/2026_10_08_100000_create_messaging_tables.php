<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every bounded context owns an outbox (what it published) and an inbox
 * (what it has consumed) in its own schema.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $contexts = ['cms', 'core'];

    public function up(): void
    {
        foreach ($this->contexts as $connection) {
            Schema::connection($connection)->create('outbox_messages', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('event_name', 120);
                $table->unsignedSmallInteger('event_version');
                $table->string('aggregate_type', 60);
                $table->string('aggregate_id', 40);
                $table->unsignedInteger('aggregate_version');
                $table->ulid('correlation_id');
                $table->ulid('causation_id')->nullable();
                $table->string('producer', 60);
                $table->json('payload');
                $table->dateTime('occurred_at', 6);
                $table->dateTime('published_at', 6)->nullable();
                $table->unsignedInteger('publish_attempts')->default(0);
                $table->string('last_error', 500)->nullable();

                // Relay scan: WHERE published_at IS NULL ORDER BY id
                $table->index(['published_at', 'id'], 'idx_outbox_unpublished');
                // Replay window + per-aggregate history
                $table->index(['occurred_at', 'event_name'], 'idx_outbox_replay');
                $table->index(['aggregate_id', 'aggregate_version'], 'idx_outbox_aggregate');
                $table->index('correlation_id', 'idx_outbox_correlation');
            });

            Schema::connection($connection)->create('inbox_messages', function (Blueprint $table) {
                $table->ulid('event_id');
                $table->string('consumer', 60);
                $table->string('event_name', 120);
                $table->unsignedSmallInteger('event_version');
                $table->string('aggregate_id', 40);
                $table->dateTime('processed_at', 6);

                $table->primary(['event_id', 'consumer']);
                $table->index(['aggregate_id', 'processed_at'], 'idx_inbox_aggregate');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->contexts as $connection) {
            Schema::connection($connection)->dropIfExists('inbox_messages');
            Schema::connection($connection)->dropIfExists('outbox_messages');
        }
    }
};

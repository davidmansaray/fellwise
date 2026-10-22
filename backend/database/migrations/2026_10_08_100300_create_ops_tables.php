<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational telemetry shared by the console: never business data.
 */
return new class extends Migration
{
    protected $connection = 'ops';

    public function up(): void
    {
        Schema::connection('ops')->create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id');
            $table->string('event_name', 120);
            $table->string('aggregate_type', 60);
            $table->string('aggregate_id', 40);
            $table->ulid('correlation_id');
            $table->string('consumer', 60);
            $table->string('outcome', 20);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('tier', 10)->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('is_replay')->default(false);
            $table->dateTime('occurred_at', 6)->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['event_id', 'id'], 'idx_attempts_event');
            $table->index(['aggregate_id', 'id'], 'idx_attempts_aggregate');
            $table->index(['outcome', 'created_at'], 'idx_attempts_outcome');
            $table->index('created_at', 'idx_attempts_created');
            $table->index('correlation_id', 'idx_attempts_correlation');
        });

        Schema::connection('ops')->create('ops_flags', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->boolean('enabled')->default(false);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::connection('ops')->create('consistency_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('checked');
            $table->unsignedInteger('drifted');
            $table->unsignedInteger('duration_ms');
            $table->dateTime('ran_at', 6)->index();
        });

        Schema::connection('ops')->create('consistency_drift', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('consistency_runs')->cascadeOnDelete();
            $table->string('aggregate_id', 40);
            $table->string('kind', 20);
            $table->unsignedInteger('cms_version')->nullable();
            $table->unsignedInteger('core_version')->nullable();

            $table->index(['run_id', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        foreach (['consistency_drift', 'consistency_runs', 'ops_flags', 'delivery_attempts'] as $table) {
            Schema::connection('ops')->dropIfExists($table);
        }
    }
};

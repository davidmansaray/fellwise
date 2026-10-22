<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bookings context (the core API) — owns bookings and the reporting read
 * models. Its view of the catalogue is a projection fed by events only.
 */
return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('catalogue_experiences', function (Blueprint $table) {
            $table->ulid('experience_id')->primary();
            $table->string('title');
            $table->string('slug');
            $table->string('region_id', 40)->index();
            $table->unsignedInteger('price_minor');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->unsignedInteger('version');
            $table->string('source', 20)->default('cms');
            $table->ulid('last_event_id');
            $table->dateTime('synced_at', 6);
        });

        DB::connection('core')->statement(
            "ALTER TABLE catalogue_experiences ADD COLUMN sync_hash CHAR(40) AS (SHA1(CONCAT_WS('|', experience_id, version, status, price_minor))) STORED, ADD INDEX idx_catalogue_sync (experience_id, sync_hash)"
        );

        Schema::connection('core')->create('bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('experience_id');
            $table->unsignedTinyInteger('party_size');
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3)->default('GBP');
            $table->date('departs_on');
            $table->dateTime('confirmed_at');
            $table->unsignedSmallInteger('lead_time_days');

            // Covering index: the lead-time histogram reads only this index.
            $table->index(['confirmed_at', 'lead_time_days'], 'idx_bookings_confirmed_lead');
            $table->index(['experience_id', 'departs_on'], 'idx_bookings_departures');
        });

        // CQRS read model for reporting: one row per experience per day.
        // PK leads with `day` so date-range aggregations are range scans.
        Schema::connection('core')->create('rm_experience_performance', function (Blueprint $table) {
            $table->date('day');
            $table->ulid('experience_id');
            $table->string('region_id', 40);
            $table->unsignedInteger('bookings')->default(0);
            $table->unsignedInteger('guests')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);

            $table->primary(['day', 'experience_id']);
            $table->index(['region_id', 'day'], 'idx_perf_region_day');
            // Covering index for date-range rollups by region (index-only scan).
            $table->index(['day', 'region_id', 'bookings', 'guests', 'revenue_minor'], 'idx_perf_day_region_cover');
            $table->index(['experience_id', 'day'], 'idx_perf_experience_day');
        });

        // Lead-time histogram read model: ~6 rows per day instead of
        // scanning every booking in the range.
        Schema::connection('core')->create('rm_lead_time', function (Blueprint $table) {
            $table->date('day');
            $table->unsignedTinyInteger('bucket');
            $table->unsignedInteger('bookings')->default(0);

            $table->primary(['day', 'bucket']);
        });
    }

    public function down(): void
    {
        foreach (['rm_lead_time', 'rm_experience_performance', 'bookings', 'catalogue_experiences'] as $table) {
            Schema::connection('core')->dropIfExists($table);
        }
    }
};

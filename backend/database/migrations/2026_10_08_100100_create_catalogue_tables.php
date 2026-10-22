<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue context (the Filament CMS) — owns editorial content.
 */
return new class extends Migration
{
    protected $connection = 'cms';

    public function up(): void
    {
        Schema::connection('cms')->create('regions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name');
            $table->string('country', 2)->default('GB');
            $table->string('legacy_code', 12)->nullable()->unique();
        });

        Schema::connection('cms')->create('guides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('display_name');
            $table->string('bio', 600)->nullable();
            $table->json('certifications')->nullable();
            $table->string('home_region_id', 40)->nullable()->index();
            $table->timestamps();
        });

        Schema::connection('cms')->create('experiences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('region_id', 40)->index();
            $table->ulid('guide_id')->nullable()->index();
            $table->text('summary')->nullable();
            $table->string('activity', 40);
            $table->string('difficulty', 20)->default('moderate');
            $table->decimal('duration_hours', 4, 1)->default(4);
            $table->unsignedSmallInteger('max_group_size')->default(8);
            $table->unsignedInteger('price_minor');
            $table->char('currency', 3)->default('GBP');
            $table->string('status', 20)->default('draft')->index();
            // Incremented once per integration event, so it is directly
            // comparable with the version Core last applied.
            $table->unsignedInteger('version')->default(0);
            $table->dateTime('published_at')->nullable();
            $table->string('legacy_id', 20)->nullable()->unique();
            $table->timestamps();
        });

        // Consistency fingerprint, computed by MySQL and indexed so the
        // checker compares hashes instead of whole rows.
        DB::connection('cms')->statement(
            "ALTER TABLE experiences ADD COLUMN sync_hash CHAR(40) AS (SHA1(CONCAT_WS('|', id, version, status, price_minor))) STORED, ADD INDEX idx_experiences_sync (id, sync_hash)"
        );

        Schema::connection('cms')->create('experience_booking_stats', function (Blueprint $table) {
            $table->ulid('experience_id')->primary();
            $table->unsignedInteger('bookings_count')->default(0);
            $table->unsignedInteger('guests_count')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->unsignedSmallInteger('next_departure_slots')->nullable();
            $table->date('next_departure_on')->nullable();
            // occurred_at of the availability event applied last; older
            // (retried, reordered) events must not overwrite newer ones.
            $table->dateTime('availability_as_of', 6)->nullable();
            $table->dateTime('last_booked_at')->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::connection('cms')->create('migration_cursors', function (Blueprint $table) {
            $table->string('source', 40)->primary();
            $table->unsignedBigInteger('last_legacy_id')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['migration_cursors', 'experience_booking_stats', 'experiences', 'guides', 'regions'] as $table) {
            Schema::connection('cms')->dropIfExists($table);
        }
    }
};

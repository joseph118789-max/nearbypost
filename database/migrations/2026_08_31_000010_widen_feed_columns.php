<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let the serving layer hold what the ingestion layer accepts.
 *
 * feed_ready_items.url is varchar(255) while news_items.url accepts 1000, which
 * is what the ingest endpoint validates against. Aggregator links routinely
 * exceed 255 - the redirect URLs are around 645 characters - so promoting one
 * of those stories threw a truncation error and the whole batch failed with a
 * 500. The story stayed in news_items and never reached the feed.
 *
 * This is not new. Any long link would have hit it, which means it has been
 * quietly losing stories for as long as aggregator items have been ingested.
 * It surfaced now only because a batch happened to fail loudly enough to notice.
 *
 * title is widened to match news_items for the same reason: a column that
 * silently refuses valid upstream data is a trap, not a constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL rather than the schema builder: doctrine/dbal is not
        // installed, and ALTER TYPE is exact about what it does here.
        DB::statement('ALTER TABLE feed_ready_items ALTER COLUMN url TYPE varchar(1000)');
        DB::statement('ALTER TABLE feed_ready_items ALTER COLUMN title TYPE varchar(500)');
        DB::statement('ALTER TABLE feed_ready_items ALTER COLUMN location_label TYPE varchar(500)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE feed_ready_items ALTER COLUMN url TYPE varchar(255)');
        DB::statement('ALTER TABLE feed_ready_items ALTER COLUMN title TYPE varchar(255)');
        DB::statement('ALTER TABLE feed_ready_items ALTER COLUMN location_label TYPE varchar(255)');
    }
};

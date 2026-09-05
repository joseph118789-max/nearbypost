<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The three lookups the pipeline does most often, none of which had an index.
 *
 * feed_ready_items.news_item_id and ai_processing_jobs.news_item_id are how
 * every stage asks "what do we already know about this story", and both tables
 * carried nothing but a primary key. Each question was a full scan. The Live
 * screen asked it three times per story and took eight and a half seconds;
 * populate-feed asks it once per story on every run, every five minutes.
 *
 * CONCURRENTLY so the site keeps serving while they build - which means these
 * cannot run inside a transaction, hence a false $withinTransaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS feed_ready_items_news_item_id_index
                       ON feed_ready_items (news_item_id)');

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ai_processing_jobs_news_item_id_index
                       ON ai_processing_jobs (news_item_id)');

        // The Live screen's window, and any "what arrived on this day" question.
        // The existing published_at indexes all lead with another column, so
        // none of them can serve a bare date range.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS news_items_published_at_index
                       ON news_items (published_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS feed_ready_items_news_item_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ai_processing_jobs_news_item_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS news_items_published_at_index');
    }
};

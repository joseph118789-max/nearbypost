<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A story that is about a period, not a moment.
 *
 * "16-20 September 2026: AKEMI Warehouse Sale" is useful for five days and then
 * worthless. Everything in this pipeline so far has been ordered by
 * published_at, which buries it the next morning while the sale is still on.
 *
 * `event_start` and `event_end` hold the window. `sources.is_event_source`
 * marks the feeds this applies to, because it must NOT apply to news: a court
 * verdict dated next Tuesday is not a story that runs until Tuesday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->date('event_start')->nullable()->after('published_at');
            $table->date('event_end')->nullable()->after('event_start');

            // A run with a start and no stated finish - "2 September onwards".
            // Kept apart from a null end so the feed can age it out on its own
            // terms rather than showing it forever.
            $table->boolean('event_open_ended')->default(false)->after('event_end');

            // The feed asks "what is still running" on every request.
            $table->index(['event_end', 'published_at'], 'news_items_event_window_index');
        });

        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->date('event_start')->nullable()->after('published_at');
            $table->date('event_end')->nullable()->after('event_start');
            $table->boolean('event_open_ended')->default(false)->after('event_end');

            $table->index(['is_active', 'event_end'], 'feed_ready_event_window_index');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('is_event_source')->default(false)->after('source_type');

            // How long an open-ended run stays up before it is treated as over.
            // A promotion "onwards" is not forever, and nobody comes back to
            // tell us it finished.
            $table->smallInteger('event_open_days')->default(30)->after('is_event_source');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex('news_items_event_window_index');
            $table->dropColumn(['event_start', 'event_end', 'event_open_ended']);
        });

        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->dropIndex('feed_ready_event_window_index');
            $table->dropColumn(['event_start', 'event_end', 'event_open_ended']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['is_event_source', 'event_open_days']);
        });
    }
};

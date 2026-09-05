<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How well we know WHEN a story was published.
 *
 *   time     the publisher gave a clock time - "9 hours ago" is honest
 *   date     the publisher gave a day and no time - say the day, not an hour
 *   scraped  the publisher gave nothing; this is when we fetched it
 *
 * Measured before this existed: 26% of a week's stories sat at exactly
 * midnight - a date pretending to be a time - and were shown as "8 hours
 * ago" at breakfast and sorted to the bottom of their own day. Another 5%
 * carried the moment they were inserted, which is not news time at all.
 *
 * The backfill reads those two signatures off the existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', fn (Blueprint $t) => $t->string('published_precision', 8)->nullable()->after('published_at'));
        Schema::table('feed_ready_items', fn (Blueprint $t) => $t->string('published_precision', 8)->nullable()->after('published_at'));

        DB::statement("
            update news_items set published_precision = case
                when abs(extract(epoch from (published_at - created_at))) < 120 then 'scraped'
                when published_at::time = '00:00:00' then 'date'
                else 'time' end
            where published_precision is null");

        DB::statement("
            update feed_ready_items f set published_precision = n.published_precision
            from news_items n where n.id = f.news_item_id and f.published_precision is null");
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', fn (Blueprint $t) => $t->dropColumn('published_precision'));
        Schema::table('news_items', fn (Blueprint $t) => $t->dropColumn('published_precision'));
    }
};

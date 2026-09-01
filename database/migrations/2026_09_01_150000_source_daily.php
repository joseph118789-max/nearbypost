<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many stories each source brought in, per day.
 *
 * Counted here rather than derived from news_items, for two reasons. Stories get
 * deleted - the archive was purged this afternoon and every per-day figure with
 * it - and a tally of what a source has delivered should survive that: it
 * describes the source, not the stories. And counting rows across a growing
 * table on every page load is work that grows without limit, while this is one
 * small row a day per source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->date('day');
            // New to us. A source re-serving the same fifty items every quarter
            // hour is not delivering anything, and counting what it offered
            // rather than what was new would say it was.
            $table->unsignedInteger('items_new')->default(0);
            $table->timestamps();
            $table->unique(['source_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_daily_stats');
    }
};

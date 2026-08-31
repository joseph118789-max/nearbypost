<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a story has a Malaysian angle - which is not the same question as
 * where it happened.
 *
 * The site is for readers in Malaysia, but the pipeline had no way to say so.
 * Anything a source published was classified and served, which is how flooding
 * at the Grand Canyon and an airshow in Zhuhai reached a Malaysian local news
 * feed.
 *
 * The obvious fix - reject anything located abroad - would be wrong, and would
 * throw away some of the most-read stories on any Malaysian news site:
 * Malaysians caught in the Nepal floods, a Malaysian team at an away fixture,
 * a trade decision in Washington that moves the ringgit. Those are Malaysian
 * stories that happen elsewhere.
 *
 * So relevance and location are recorded separately. A story keeps whatever
 * coordinates it truly has - Kathmandu stays in Kathmandu - and is judged on
 * whether a reader here has reason to care.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->boolean('malaysia_relevant')->nullable()->after('gps_flag');
            $table->string('relevance_reason', 120)->nullable()->after('malaysia_relevant');

            $table->index(['malaysia_relevant', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['malaysia_relevant', 'published_at']);
            $table->dropColumn(['malaysia_relevant', 'relevance_reason']);
        });
    }
};

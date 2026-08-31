<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One story that happened in many places at once.
 *
 * Everything until now has had a single location, which is true of almost all
 * news: a fire is at an address, a court sits in a town. But a nationwide
 * promotion, a chain closing its branches, a utility cutting supply across
 * several districts - those genuinely happen in twenty places, and pinning such
 * a story to one of them means nineteen sets of readers never see something
 * that is happening on their doorstep.
 *
 * A story therefore gets a list of places, and one serving row per place. The
 * reader is shown it once, at whichever of its places is nearest to them, which
 * is what `is_primary_location` and the nearby query's DISTINCT ON between them
 * arrange.
 *
 * `is_primary_location` marks exactly one row per story so the non-distance
 * feeds - By Interest, category pages - can filter on an indexed boolean rather
 * than running a de-duplicating subquery over ten thousand rows to solve a
 * problem that only a handful of stories have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_locations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('news_item_id');
            $table->string('label', 200);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // 'ai' when proposed by the model, 'manual' when a person typed or
            // corrected it. Worth keeping: a list a person checked is a
            // different thing from a list a model guessed.
            $table->string('added_by', 20)->default('ai');
            $table->string('geocode_status', 20)->nullable();

            $table->timestamps();

            $table->index('news_item_id');
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->boolean('is_multi_point')->default(false);
        });

        Schema::table('feed_ready_items', function (Blueprint $table) {
            // True for every existing row: one story, one place, and that place
            // is its primary one.
            $table->boolean('is_primary_location')->default(true);

            $table->index(['is_active', 'is_primary_location', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'is_primary_location', 'published_at']);
            $table->dropColumn('is_primary_location');
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn('is_multi_point');
        });

        Schema::dropIfExists('story_locations');
    }
};

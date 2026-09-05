<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Places no map could identify, held for a person to look at.
 *
 * A property launch given only as a PT lot number, a village too small for any
 * gazetteer, a building known locally by a name nobody has registered. The
 * pipeline should not guess at these and it should not silently drop them - it
 * should put them somewhere a person can see, because a person can often place
 * them in seconds from local knowledge no database holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_reviews', function (Blueprint $table) {
            $table->id();

            // What we were asked to find, and the story that asked.
            $table->string('place_text', 300);
            $table->string('place_key', 300)->index();
            $table->foreignId('news_item_id')->nullable();
            $table->string('story_title', 300)->nullable();
            $table->string('story_url', 600)->nullable();
            $table->string('source', 120)->nullable();

            // Every door we tried and what came back, so the person reviewing
            // starts from what has already been ruled out rather than
            // repeating it.
            $table->jsonb('attempts')->nullable();

            $table->string('status', 20)->default('pending')->index();

            // What the person decided.
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('resolved_label', 300)->nullable();
            $table->text('note')->nullable();
            $table->string('resolved_by', 120)->nullable();
            $table->timestamp('resolved_at')->nullable();

            // How many stories are waiting on this one place. A name that
            // blocks forty stories should be looked at before one that
            // blocks a single story.
            $table->unsignedInteger('story_count')->default(1);

            $table->timestamps();

            // One row per place, not per story: the same unknown village
            // arriving five times is one question, asked once.
            $table->unique('place_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_reviews');
    }
};

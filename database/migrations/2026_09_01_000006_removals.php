<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why things were taken down, kept so the reviewer can learn from it.
 *
 * A reason written on the story itself is lost the moment the story is deleted,
 * and it is invisible in aggregate - which is the only form in which it is
 * useful. One removal is an incident. Forty removals are a pattern, and a
 * pattern is a rule waiting to be written.
 *
 * So a removal is recorded here as its own fact, carrying enough of the story
 * to be read months later without the story still existing: the headline, who
 * wrote it, and the editor's reason in their own words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('removals', function (Blueprint $table) {
            $table->id();

            // Kept as plain columns rather than a join, so a removal survives
            // the deletion of the story it describes.
            $table->unsignedBigInteger('news_item_id')->nullable();
            $table->string('title', 500);
            $table->string('origin', 20)->default('scraper');
            $table->string('source', 255)->nullable();
            $table->string('primary_category', 100)->nullable();

            $table->text('reason');

            $table->unsignedBigInteger('removed_by')->nullable();

            // Set once a reason has been folded into a proposed rule, so the
            // same forty removals do not keep suggesting the same rule.
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('reviewed_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('removals');
    }
};

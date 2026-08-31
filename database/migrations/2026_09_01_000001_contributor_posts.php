<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stories written by readers, alongside stories gathered by the scraper.
 *
 * A contributed post is a news_item like any other, so it flows through the
 * same serving layer, the same de-duplication and the same feed queries rather
 * than becoming a second kind of story with its own parallel plumbing. What it
 * needs on top is the few things a scraped article never has: who wrote it, the
 * text they wrote, a picture they may have attached, and a record of what the
 * review decided and why - the contributor has to be able to read the reason
 * their post was turned down.
 *
 * `origin` is the column the reader's Source filter reads. It carries a default
 * so the 11,392 stories already gathered are correctly 'scraper' without a
 * backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->string('origin', 20)->default('scraper');
            $table->unsignedBigInteger('contributor_id')->nullable();
            $table->string('section', 20)->nullable();
            $table->text('body')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('review_status', 20)->nullable();
            $table->string('review_reason', 300)->nullable();

            $table->index(['origin', 'published_at']);
            $table->index(['contributor_id', 'created_at']);
        });

        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->string('origin', 20)->default('scraper');
            $table->string('image_path', 255)->nullable();

            $table->index('origin');
        });
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->dropIndex(['origin']);
            $table->dropColumn(['origin', 'image_path']);
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['origin', 'published_at']);
            $table->dropIndex(['contributor_id', 'created_at']);
            $table->dropColumn([
                'origin', 'contributor_id', 'section',
                'body', 'image_path', 'review_status', 'review_reason',
            ]);
        });
    }
};

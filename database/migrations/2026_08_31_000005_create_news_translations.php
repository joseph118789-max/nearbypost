<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Translations of each story's headline and summary.
 *
 * Malaysian news is published in Malay, Chinese, Tamil and English, and the
 * stories worth reading do not arrive in the language the reader wants. Until
 * now the pipeline could only surface a story to people who already read its
 * source language, which quietly excluded most of the audience from most of the
 * coverage.
 *
 * A separate table rather than columns on news_items, for two reasons: the set
 * of languages will change, and most stories will never be translated into
 * every language we eventually support. A row per translation keeps the common
 * case cheap and the rare case possible.
 *
 * The original headline and summary stay on news_items. A missing translation
 * falls back to them rather than showing nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_item_id');
            $table->string('locale', 8);
            $table->string('title', 600);
            $table->text('summary')->nullable();
            $table->string('model', 60)->nullable();
            $table->timestamps();

            $table->unique(['news_item_id', 'locale']);
            $table->index('locale');
        });

        Schema::table('news_items', function (Blueprint $table) {
            // The language the publisher wrote in, as detected during
            // enrichment. Kept so a reader can be told what they are clicking
            // through to, and so coverage by language can be measured.
            $table->string('source_language', 8)->nullable()->after('secondary_category');
            $table->timestamp('translated_at')->nullable()->after('source_language');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn(['source_language', 'translated_at']);
        });

        Schema::dropIfExists('news_translations');
    }
};

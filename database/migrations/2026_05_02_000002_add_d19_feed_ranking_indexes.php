<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Index 1: default feed + category feed
        // Pattern: WHERE is_active = true ORDER BY published_at DESC
        // Supports: default() and byCategory() ordering
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->index(['is_active', 'published_at'], 'feed_ready_is_active_published_idx');
        });

        // Index 2: category feed
        // Pattern: WHERE is_active = true AND primary_category = ? ORDER BY published_at DESC
        // Supports: byCategory() WHERE + ORDER
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->index(['is_active', 'primary_category', 'published_at'], 'feed_ready_is_active_cat_published_idx');
        });

        // Index 3: geo queries
        // Pattern: WHERE is_active = true AND is_article = true AND precision_type IN ('exact_area','approximate_area')
        // Supports: nearby() and filter(radius) — filters out non-geo items before distance calc
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->index(['is_active', 'is_article', 'precision_type'], 'feed_ready_is_active_article_precision_idx');
        });
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->dropIndex('feed_ready_is_active_published_idx');
            $table->dropIndex('feed_ready_is_active_cat_published_idx');
            $table->dropIndex('feed_ready_is_active_article_precision_idx');
        });
    }
};
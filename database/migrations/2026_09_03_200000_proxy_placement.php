<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A story placed at a stand-in - another place the same story names, because
 * the place it happened at is on no map - says so, on the story and on the
 * review card that still waits for the exact spot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', fn (Blueprint $t) => $t->text('geo_note')->nullable()->after('geocode_provider'));
        Schema::table('place_reviews', fn (Blueprint $t) => $t->jsonb('placed_at')->nullable()->after('suggestions'));
    }

    public function down(): void
    {
        Schema::table('news_items', fn (Blueprint $t) => $t->dropColumn('geo_note'));
        Schema::table('place_reviews', fn (Blueprint $t) => $t->dropColumn('placed_at'));
    }
};

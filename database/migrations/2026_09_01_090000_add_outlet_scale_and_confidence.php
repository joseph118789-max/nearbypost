<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the model's doubt and the model's verdict on scale somewhere of their
 * own to live.
 *
 * Doubt was previously written into story_locations.geocode_status, which the
 * geocoder overwrites the moment it finds coordinates - so a branch the model
 * had flagged as a guess reached the editor looking exactly as solid as one it
 * was certain of. One column, two meanings, and the later writer won.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_locations', function (Blueprint $table) {
            // Null where nothing proposed it - a place typed by a person is
            // neither confident nor doubtful, it is simply theirs.
            $table->boolean('ai_confident')->nullable()->after('added_by');
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->string('outlet_scale', 20)->nullable();
            $table->text('outlet_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('story_locations', function (Blueprint $table) {
            $table->dropColumn('ai_confident');
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn(['outlet_scale', 'outlet_note']);
        });
    }
};

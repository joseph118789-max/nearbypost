<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add location coordinates and category preferences to subscribers
     * for relevance scoring.
     */
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            // Location coordinates for distance-based scoring
            $table->decimal('latitude', 10, 7)->nullable()->after('group_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('city', 100)->nullable()->after('longitude');
            
            // Category preferences (JSON array)
            $table->json('preferred_categories')->nullable()->after('city');
            
            // Maximum distance preference (km)
            $table->decimal('max_distance_km', 6, 1)->nullable()->after('preferred_categories');
            
            // Last score calculation timestamp
            $table->timestamp('last_scored_at')->nullable()->after('max_distance_km');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn([
                'latitude',
                'longitude', 
                'city',
                'preferred_categories',
                'max_distance_km',
                'last_scored_at',
            ]);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            // Location-based preferences
            $table->json('preferred_categories')->nullable()->after('preferences');
            $table->decimal('alert_radius_km', 8, 2)->default(10.00)->after('preferred_categories');
            $table->decimal('location_lat', 10, 8)->nullable()->after('alert_radius_km');
            $table->decimal('location_lng', 11, 8)->nullable()->after('location_lat');
            $table->string('notification_frequency')->default('immediate')->after('location_lng');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_categories',
                'alert_radius_km',
                'location_lat',
                'location_lng',
                'notification_frequency',
            ]);
        });
    }
};
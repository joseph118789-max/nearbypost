<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->json('preferred_categories')->nullable()->after('group_id');
            $table->decimal('alert_radius_km', 8, 2)->nullable()->after('preferred_categories');
            $table->decimal('location_lat', 10, 7)->nullable()->after('alert_radius_km');
            $table->decimal('location_lng', 10, 7)->nullable()->after('location_lat');
            $table->enum('notification_frequency', ['immediate', 'hourly', 'daily', 'weekly'])->nullable()->after('location_lng');
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

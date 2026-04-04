<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->string('canonical_place_name', 500)->nullable()->after('location_label');
            $table->decimal('geo_confidence_score', 5, 4)->nullable()->after('precision_type');
            $table->string('coverage_type', 30)->nullable()->after('geo_confidence_score');
            $table->timestamp('sort_timestamp')->nullable()->after('coverage_type');
        });
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->dropColumn([
                'canonical_place_name',
                'geo_confidence_score',
                'coverage_type',
                'sort_timestamp',
            ]);
        });
    }
};

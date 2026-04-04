<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expand relevance_mode and ai_status enums on ai_processing_jobs
        Schema::table('ai_processing_jobs', function (Blueprint $table) {
            // Drop old enums and recreate as varchar for broader compatibility
            $table->string('relevance_mode', 30)->default('category_only')->change();
            $table->string('ai_status', 30)->default('pending')->change();
            // Add new tracking fields
            $table->string('pipeline_version', 20)->default('v1.0')->after('prompt_version');
            $table->longText('raw_ai_output')->nullable()->after('error_message');
            $table->longText('validated_summary')->nullable()->after('raw_ai_output');
            $table->string('validated_category', 100)->nullable()->after('validated_summary');
            $table->string('validated_place', 500)->nullable()->after('validated_category');
            $table->string('validation_notes', 500)->nullable()->after('validated_place');
        });

        // Add alias and geocode fields to news_items
        Schema::table('news_items', function (Blueprint $table) {
            // Alias enrichment
            $table->string('canonical_place_name', 500)->nullable()->after('main_place_text');
            $table->string('alias_match_status', 30)->nullable()->after('canonical_place_name'); // matched, unmatched, ambiguous, empty
            $table->string('alias_match_type', 30)->nullable()->after('alias_match_status');     // city, district, region, area
            $table->timestamp('alias_matched_at')->nullable()->after('alias_match_type');

            // Geocoding
            $table->decimal('latitude', 10, 7)->nullable()->after('alias_match_type');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('geocode_status', 30)->nullable()->after('longitude'); // success, failed, rate_limited, skipped_category_only, skipped_unmatched
            $table->string('geocode_provider', 50)->nullable()->after('geocode_status');
            $table->decimal('geocode_confidence', 5, 4)->nullable()->after('geocode_provider');
            $table->timestamp('geocoded_at')->nullable()->after('geocode_confidence');

            // Precision & coverage
            $table->string('precision_type', 30)->nullable()->change(); // exact_area, approximate_area, region, broad, unknown
            $table->string('coverage_type', 30)->nullable()->after('precision_type'); // nearby-eligible, broader-only, too-vague
            $table->decimal('geo_confidence_score', 5, 4)->nullable()->after('coverage_type');
            $table->string('coverage_status', 30)->nullable()->after('geo_confidence_score');
            $table->timestamp('geo_processed_at')->nullable()->after('coverage_status');

            // Remove old unstructured geo_confidence
            $table->dropColumn('geo_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('ai_processing_jobs', function (Blueprint $table) {
            $table->string('relevance_mode', 30)->default('national')->change();
            $table->string('ai_status', 30)->default('pending')->change();
            $table->dropColumn([
                'pipeline_version',
                'raw_ai_output',
                'validated_summary',
                'validated_category',
                'validated_place',
                'validation_notes',
            ]);
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn([
                'canonical_place_name',
                'alias_match_status',
                'alias_match_type',
                'alias_matched_at',
                'latitude',
                'longitude',
                'geocode_status',
                'geocode_provider',
                'geocode_confidence',
                'geocoded_at',
                'coverage_type',
                'geo_confidence_score',
                'coverage_status',
                'geo_processed_at',
            ]);
            $table->float('geo_confidence')->nullable()->after('precision_type');
        });
    }
};

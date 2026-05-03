<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Supplemental schema repair: restore canonical geo pipeline columns.
 *
 * The failed D11 migration (2026_04_05_000000_d11_ai_enrichment_hardening)
 * partially ran — it dropped old float geo_confidence and replaced it with
 * numeric(5,4), but failed before creating the rest of the canonical geo columns.
 *
 * This migration adds ONLY the 10 missing columns using Schema::hasColumn()
 * guards for idempotency. No drops. No data mutation. Legacy lat/lng untouched.
 * No precision_type resize — that cleanup is a separate follow-up task.
 *
 * Columns added:
 *   alias_match_type, alias_matched_at, latitude, longitude,
 *   geocode_provider, geocoded_at, coverage_type,
 *   geo_confidence_score, coverage_status, geo_processed_at
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Alias enrichment fields ──────────────────────────────────
        $this->addColumnIfAbsent('alias_match_type', function (Blueprint $table) {
            $table->string('alias_match_type', 30)->nullable()->after('alias_match_status');
        });

        $this->addColumnIfAbsent('alias_matched_at', function (Blueprint $table) {
            $table->timestamp('alias_matched_at')->nullable()->after('alias_match_type');
        });

        // ── Canonical geo coordinates ─────────────────────────────────
        // Primary lat/lng columns going forward. Legacy lat/lng (numeric)
        // are preserved as-is for backward compat.
        $this->addColumnIfAbsent('latitude', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('alias_matched_at');
        });

        $this->addColumnIfAbsent('longitude', function (Blueprint $table) {
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        // ── Geocoding provenance ───────────────────────────────────────
        $this->addColumnIfAbsent('geocode_provider', function (Blueprint $table) {
            $table->string('geocode_provider', 50)->nullable()->after('geocode_status');
        });

        $this->addColumnIfAbsent('geocoded_at', function (Blueprint $table) {
            $table->timestamp('geocoded_at')->nullable()->after('geocode_confidence');
        });

        // ── Precision and coverage fields ──────────────────────────────
        $this->addColumnIfAbsent('coverage_type', function (Blueprint $table) {
            $table->string('coverage_type', 30)->nullable()->after('precision_type');
        });

        $this->addColumnIfAbsent('geo_confidence_score', function (Blueprint $table) {
            $table->decimal('geo_confidence_score', 5, 4)->nullable()->after('coverage_type');
        });

        $this->addColumnIfAbsent('coverage_status', function (Blueprint $table) {
            $table->string('coverage_status', 30)->nullable()->after('geo_confidence_score');
        });

        $this->addColumnIfAbsent('geo_processed_at', function (Blueprint $table) {
            $table->timestamp('geo_processed_at')->nullable()->after('coverage_status');
        });
    }

    public function down(): void
    {
        // Remove only what this migration added. Does NOT touch legacy lat/lng.
        $toRemove = [
            'geo_processed_at',
            'coverage_status',
            'geo_confidence_score',
            'coverage_type',
            'geocoded_at',
            'geocode_provider',
            'longitude',
            'latitude',
            'alias_matched_at',
            'alias_match_type',
        ];

        foreach ($toRemove as $column) {
            $this->dropColumnIfPresent($column);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Add a column only if it does not already exist in news_items.
     * Uses Laravel's Schema facade for framework consistency.
     */
    private function addColumnIfAbsent(string $column, callable $definition): void
    {
        if (!Schema::hasColumn('news_items', $column)) {
            Schema::table('news_items', $definition);
        }
    }

    /**
     * Drop a column only if it exists in news_items.
     */
    private function dropColumnIfPresent(string $column): void
    {
        if (Schema::hasColumn('news_items', $column)) {
            Schema::table('news_items', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
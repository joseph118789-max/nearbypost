<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Classify geocoded items into precision tiers and coverage types.
 * Reads from news_items (latitude, longitude, geocode_confidence, alias_type, relevance_mode).
 * Writes to news_items (precision_type, coverage_type, geo_confidence_score, coverage_status, geo_processed_at).
 */
class InterpretLocationPrecision extends Command
{
    protected $signature = 'ingest:interpret-precision
        {--news_item_id= : Process a specific item}
        {--limit=100    : Max items per run}';

    protected $description = 'Classify geocoded items into precision tiers and coverage types';

    // ── Precision classification ────────────────────────────────────────────
    private const PRECISION_EXACT      = 'exact_area';
    private const PRECISION_APPROX    = 'approximate_area';
    private const PRECISION_REGION    = 'region';
    private const PRECISION_BROAD     = 'broad';
    private const PRECISION_UNKNOWN   = 'unknown';

    // ── Coverage states ─────────────────────────────────────────────────────
    private const COVERAGE_NEARBY      = 'nearby-eligible';     // Can use geo-distance targeting
    private const COVERAGE_BROADER     = 'broader-only';        // Only broad matching
    private const COVERAGE_TOO_VAGUE   = 'too-vague';           // Cannot use geo-targeting
    private const COVERAGE_SKIPPED     = 'skipped';             // category_only or not geocoded

    // ── Geocode confidence thresholds ─────────────────────────────────────
    private const CONF_HIGH   = 0.8;
    private const CONF_MEDIUM = 0.5;

    private function backfillLegacyConfidence(): void
    {
        // Items with lat/lng but no geocode_confidence — these are legacy items
        // that were geocoded by the old pipeline but never got a confidence score.
        // Assign a default confidence of 0.6 (medium-high) as a reasonable proxy.
        $updated = NewsItem::whereNull('geocode_confidence')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($q) {
                $q->whereNull('geocode_status')
                  ->orWhere('geocode_status', '');
            })
            ->update(['geocode_confidence' => 0.6]);
        $this->info("Backfilled geocode_confidence=0.6 for {$updated} legacy items.");
    }

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');
        $limit     = (int) $this->option('limit');

        // First pass: backfill geocode_confidence for legacy items
        $this->backfillLegacyConfidence();

        $query = NewsItem::query()
            ->where(function ($q) {
                // Items that went through the full pipeline
                $q->where('geocode_status', 'success');
                // OR items with legacy lat/lng that were geocoded but never marked
                $q->orWhere(function ($q2) {
                    $q2->whereNull('geocode_status')
                       ->whereNotNull('latitude')
                       ->whereNotNull('longitude');
                });
            })
            ->where(function ($q) {
                $q->whereNull('coverage_status')
                  ->orWhere('coverage_status', '')
                  ->orWhereNotIn('coverage_status', [self::COVERAGE_NEARBY, self::COVERAGE_BROADER]);
            });

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        }

        // Newest first: a stage that cannot clear its backlog should
        // spend its limit on today's news, not on the same old stories
        // that have failed every run for months.
        $items = $query->orderByDesc('published_at')->limit($limit)->get();
        $this->info("Interpreting precision: {$items->count()} items.");

        foreach ($items as $item) {
            $this->interpret($item);
        }

        $this->info('Done.');
        return 0;
    }

    private function interpret(NewsItem $item): void
    {
        // ── Skip category_only upstream ─────────────────────────────────
        if ($item->relevance_mode === 'category_only') {
            $item->update([
                'precision_type'    => null,
                'coverage_type'    => null,
                'geo_confidence_score' => null,
                'coverage_status' => self::COVERAGE_SKIPPED,
                'geo_processed_at' => now(),
            ]);
            $this->line("  SKIP-cat_only {$item->id}");
            return;
        }

        $precision  = $this->classifyPrecision($item);
        $coverage   = $this->classifyCoverage($item, $precision);
        $score      = $this->deriveConfidenceScore($item, $precision);

        $item->update([
            'precision_type'       => $precision,
            'coverage_type'        => $coverage,
            'geo_confidence_score' => $score,
            'coverage_status'      => $coverage === self::COVERAGE_NEARBY ? self::COVERAGE_NEARBY
                              : ($coverage === self::COVERAGE_BROADER ? self::COVERAGE_BROADER
                              : self::COVERAGE_TOO_VAGUE),
            'geo_processed_at'     => now(),
        ]);

        $this->line("  {$item->id} | precision={$precision} | coverage={$coverage} | score={$score}");

        Log::info('Precision interpreted', [
            'news_item_id' => $item->id,
            'precision'    => $precision,
            'coverage'     => $coverage,
            'score'        => $score,
        ]);
    }

    /**
     * Classify precision based on geocode confidence, alias type, and place name.
     */
    private function classifyPrecision(NewsItem $item): string
    {
        $confidence  = (float) ($item->geocode_confidence ?? 0);
        $aliasType   = $item->alias_match_type ?? '';
        $placeName   = mb_strtolower($item->canonical_place_name ?? '');

        // ── High-importance + city/area alias → exact ─────────────────
        if ($confidence >= self::CONF_HIGH && in_array($aliasType, ['city', 'area'], true)) {
            return self::PRECISION_EXACT;
        }

        // ── Medium+ confidence + district/area → approximate ───────────
        if ($confidence >= self::CONF_MEDIUM && in_array($aliasType, ['district', 'area'], true)) {
            return self::PRECISION_APPROX;
        }

        // ── High confidence but region-level alias → region ────────────
        if ($confidence >= self::CONF_MEDIUM && $aliasType === 'region') {
            return self::PRECISION_REGION;
        }

        // ── Low confidence or vague place names → broad ────────────────
        if ($aliasType === 'region' || strpos($placeName, 'valley') !== false || strpos($placeName, 'coast') !== false) {
            return self::PRECISION_BROAD;
        }

        // ── No confidence data and no clear type → unknown ─────────────
        if ($confidence <= 0 && empty($aliasType)) {
            return self::PRECISION_UNKNOWN;
        }

        // Default fallback
        if ($confidence >= self::CONF_MEDIUM) {
            return self::PRECISION_APPROX;
        }

        return self::PRECISION_BROAD;
    }

    /**
     * Classify coverage based on precision type and relevance mode.
     */
    private function classifyCoverage(NewsItem $item, string $precision): string
    {
        // nearby-eligible: can target within a radius
        if (in_array($precision, [self::PRECISION_EXACT, self::PRECISION_APPROX], true)) {
            return self::COVERAGE_NEARBY;
        }

        // broader-only: can match at region/city level but not precise radius
        if ($precision === self::PRECISION_REGION) {
            return self::COVERAGE_BROADER;
        }

        // too vague for any geo targeting
        return self::COVERAGE_TOO_VAGUE;
    }

    /**
     * Derive a 0–1 confidence score for feed ranking use.
     * Combines geocoder importance with business logic on alias type.
     */
    private function deriveConfidenceScore(NewsItem $item, string $precision): float
    {
        $geocodeConf = (float) ($item->geocode_confidence ?? 0);

        $precisionMultiplier = match ($precision) {
            self::PRECISION_EXACT     => 1.0,
            self::PRECISION_APPROX    => 0.8,
            self::PRECISION_REGION    => 0.5,
            self::PRECISION_BROAD     => 0.3,
            self::PRECISION_UNKNOWN   => 0.1,
            default                   => 0.1,
        };

        // Use the higher of the two scores
        $score = max($geocodeConf, $precisionMultiplier * 1.0);

        return round(min(1.0, $score), 4);
    }
}

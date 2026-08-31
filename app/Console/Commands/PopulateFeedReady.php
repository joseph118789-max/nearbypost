<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\FeedReadyItem;
use App\Models\AiProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\DuplicateGuard;

/**
 * Precompute feed_ready rows from fully-processed news_items.
 * Idempotent: upserts per news_item_id, only creates from items
 * that have passed through alias enrichment and precision interpretation.
 *
 * PROMOTION GATE — this command is the authoritative path from
 * canonical pipeline state into public serving state.
 * "When in doubt, skip."
 */
class PopulateFeedReady extends Command
{
    protected $signature = 'ingest:populate-feed
        {--news_item_id= : Populate a specific item only}
        {--limit=100    : Max items per run}
        {--all          : Ignore the up-to-date check and re-sync everything}';

    protected $description = 'Upsert feed_ready_items from processed news_items (idempotent)';

    // ── Canonical relevance modes (D11 contract) ───────────────────────────
    private const ALLOWED_RELEVANCE_MODES = ['category_only', 'location_only', 'location_and_category'];

    // ── Valid geo precision types for geo-bearing items ─────────────────
    private const VALID_PRECISION_TYPES = ['exact_area', 'approximate_area', 'region', 'broad'];

    // ── Allowed AI job statuses for promotion ───────────────────────────
    private const ALLOWED_AI_STATUSES = ['success', 'fallback_used'];

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');
        $limit     = (int) $this->option('limit');

        $query = NewsItem::query()
            ->where('status', 'active')
            ->whereNotNull('title')
            ->whereNotNull('published_at');

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        } elseif (!$this->option('all')) {
            // Without this predicate the command re-processed the same first
            // 100 ids on every run and never reached the rest of the table.
            $query->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('feed_ready_items')
                  ->whereColumn('feed_ready_items.news_item_id', 'news_items.id')
                  ->whereColumn('feed_ready_items.updated_at', '>=', 'news_items.updated_at');
            });
        }

        $items = $query->limit($limit)->orderBy('id')->get();
        $this->info("Populating feed_ready: {$items->count()} items.");

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $result = $this->upsert($item);
            if ($result === 'created') $created++;
            elseif ($result === 'updated') $updated++;
            else $skipped++;
        }

        $this->info("Done. created={$created} updated={$updated} skipped={$skipped}");
        return 0;
    }


    /**
     * Choose the best category for a feed_ready item.
     * Prefers AI-enriched category, falls back intelligently.
     */
    private function bestCategory(NewsItem $item, $aiJob): string
    {
        $aiCategory = $item->ai_category;
        $validatedCat = $aiJob?->validated_category;

        // If NewsItem has a proper AI category (not 'others'/'other'), use it
        if ($aiCategory && !in_array(strtolower($aiCategory), ['others', 'other', ''], true)) {
            return $aiCategory;
        }

        // If AI job has a proper validated_category, use it
        if ($validatedCat && !in_array(strtolower($validatedCat), ['others', 'other', ''], true)) {
            return $validatedCat;
        }

        // Fall back to RSS primary_category only if it's a real category (not 'others'/'news')
        $rssCat = $item->primary_category;
        if ($rssCat && !in_array(strtolower($rssCat), ['others', 'other', 'news', ''], true)) {
            return $rssCat;
        }

        // Last resort
        return $aiCategory ?: $validatedCat ?: $rssCat ?: 'other';
    }

    /**
     * Upsert a single feed_ready_item.
     * Returns 'created', 'updated', or 'skipped'.
     *
     * Promotion gate logic:
     * 1. AI job must exist and have allowed status (success / fallback_used)
     * 2. relevance_mode must be in the canonical set
     * 3. category_only items: promote without geo requirements
     * 4. location_only / location_and_category items: require location label
     *    AND valid precision type — prevents half-baked geo rows reaching feed
     * 5. category_only items: allowed without geo
     * 6. location-bearing items: require location label + valid precision
     */
    private function upsert(NewsItem $item): string
    {
        // ── Gate 0: an item refused by policy is never served ───────────
        if ($item->discarded) {
            return 'skipped';
        }

        // A story with no Malaysian angle is not for this audience, wherever
        // it happened.
        if ($item->malaysia_relevant === false) {
            return 'skipped';
        }

        // ── Gate 1: AI enrichment must exist and be in allowed state ────
        $aiJob = $item->aiProcessingJob;
        if (!$aiJob || !in_array($aiJob->ai_status, self::ALLOWED_AI_STATUSES, true)) {
            // skipped_no_ai_job
            return 'skipped';
        }

        // ── Gate 2: relevance_mode must be in canonical set ────────────
        $mode = $aiJob->relevance_mode ?: $item->relevance_mode;
        if (!in_array($mode, self::ALLOWED_RELEVANCE_MODES, true)) {
            // skipped_invalid_mode — coerce nothing, just skip
            return 'skipped';
        }

        // ── Gate 3: location_label source must exist ───────────────────
        $locationLabel = $item->canonical_place_name ?: $item->main_place_text;

        // ── Gate 4: geo-bearing items require valid precision ───────────
        // category_only: promoted without geo requirements
        // location_only / location_and_category: require location label AND valid precision
        if ($mode !== 'category_only') {
            $precision = $item->precision_type ?? null;
            $hasValidPrecision = in_array($precision, self::VALID_PRECISION_TYPES, true);

            if (empty($locationLabel) || !$hasValidPrecision) {
                // skipped_incomplete_geo — half-baked location row, do not serve
                return 'skipped';
            }
        }

        // ── All gates passed: build serving row ─────────────────────────
        $precision = $item->precision_type ?? null;
        if ($precision && !in_array($precision, self::VALID_PRECISION_TYPES, true)) {
            $precision = null;
        }

        $row = [
            'title'              => $item->title,
            'summary'            => $aiJob->validated_summary ?: $item->summary,
            'source'             => $item->source,
            'url'               => $item->url,
            'published_at'       => $item->published_at,
            'primary_category'   => $this->bestCategory($item, $aiJob),
            'secondary_category' => $item->secondary_category,
            'sub_category'       => $item->sub_category,
            'location_label'     => $locationLabel ?: null,
            'lat'                => $item->latitude,
            'lng'                => $item->longitude,
            'precision_type'    => $precision,
            'distance_km'        => null,
            'relevance_mode'     => $mode,
            'is_article'         => $aiJob->is_article ?? true,
            'is_active'          => true,
            // Extended serving fields
            'canonical_place_name' => $item->canonical_place_name,
            'geo_confidence_score'=> $item->geo_confidence_score,
            'coverage_type'       => $item->coverage_type,
            'sort_timestamp'      => $item->published_at,
            'origin'              => $item->origin ?: 'scraper',
            'image_path'          => $item->image_path,
            'updated_at'          => now(),
        ];

        $existing = FeedReadyItem::where('news_item_id', $item->id)->first();

        // Last check before serving: does the reader already have this story
        // from another source? Batch de-duplication cannot see across runs.
        if (!$existing) {
            $guard = new DuplicateGuard();
            $twin  = $guard->findPublished($item->title, (string) $item->published_at, $item->id);

            if ($twin) {
                if (!$guard->preferIncoming((object) $row, $twin)) {
                    return 'skipped';
                }

                // The incoming version is better: take over the served slot so
                // the reader's link points at the article, not a redirect.
                FeedReadyItem::where('id', $twin->id)->update(['is_active' => false]);
                Log::info('Duplicate superseded', [
                    'kept'      => $item->id,
                    'superseded'=> $twin->news_item_id,
                    'title'     => mb_substr($item->title, 0, 80),
                ]);
            }
        }

        if ($existing) {
            $existing->update($row);
            Log::debug('FeedReady updated', ['news_item_id' => $item->id]);
            return 'updated';
        }

        $row['news_item_id'] = $item->id;
        $row['created_at']   = now();
        FeedReadyItem::create($row);
        Log::debug('FeedReady created', ['news_item_id' => $item->id]);
        return 'created';
    }
}

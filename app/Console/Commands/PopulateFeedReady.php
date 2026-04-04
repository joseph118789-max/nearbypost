<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\FeedReadyItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Precompute feed_ready rows from fully-processed news_items.
 * Idempotent: upserts per news_item_id, only creates from items
 * that have passed through alias enrichment and precision interpretation.
 */
class PopulateFeedReady extends Command
{
    protected $signature = 'ingest:populate-feed
        {--news_item_id= : Populate a specific item only}
        {--limit=100    : Max items per run}';

    protected $description = 'Upsert feed_ready_items from processed news_items (idempotent)';

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
     * Upsert a single feed_ready_item.
     * Returns 'created', 'updated', or 'skipped'.
     */
    private function upsert(NewsItem $item): string
    {
        // ── Only serve items that have passed AI enrichment ─────────────
        $aiJob = $item->aiProcessingJob;
        if (!$aiJob || !in_array($aiJob->ai_status, ['success', 'fallback_used'], true)) {
            return 'skipped';
        }

        // Determine location_label from canonical or raw place name
        $locationLabel = $item->canonical_place_name ?: $item->main_place_text;

        // precision_type: only pass through if it maps to a feed-serving precision
        // (category_only items won't have geo data; that's fine)
        $precision = $item->precision_type ?? null;
        $validPrecisions = ['exact_area', 'approximate_area', 'region', 'broad'];
        if ($precision && !in_array($precision, $validPrecisions, true)) {
            $precision = null;
        }

        // Build the row data
        $row = [
            'title'              => $item->title,
            'summary'            => $aiJob->validated_summary ?: $item->summary,
            'source'             => $item->source,
            'url'               => $item->url,
            'published_at'       => $item->published_at,
            'primary_category'   => $aiJob->validated_category ?: $item->primary_category,
            'secondary_category' => $item->secondary_category,
            'location_label'     => $locationLabel,
            'lat'                => $item->latitude,
            'lng'                => $item->longitude,
            'precision_type'    => $precision,
            'distance_km'        => null,
            'relevance_mode'     => $aiJob->relevance_mode ?: $item->relevance_mode,
            'is_active'          => true,
            // Extended serving fields
            'canonical_place_name' => $item->canonical_place_name,
            'geo_confidence_score'=> $item->geo_confidence_score,
            'coverage_type'       => $item->coverage_type,
            'sort_timestamp'      => $item->published_at,
            'updated_at'          => now(),
        ];

        $existing = FeedReadyItem::where('news_item_id', $item->id)->first();

        if ($existing) {
            // Only update if source data has actually changed
            $dirty = false;
            foreach (['title','summary','primary_category','location_label','lat','lng',
                       'precision_type','relevance_mode','canonical_place_name',
                       'geo_confidence_score','coverage_type'] as $field) {
                if ($existing->$field !== $row[$field]) { $dirty = true; break; }
            }

            if ($dirty) {
                $existing->update($row);
                Log::debug('FeedReady updated', ['news_item_id' => $item->id]);
                return 'updated';
            }
            return 'skipped';
        }

        $row['news_item_id'] = $item->id;
        $row['created_at']   = now();
        FeedReadyItem::create($row);
        Log::debug('FeedReady created', ['news_item_id' => $item->id]);
        return 'created';
    }
}

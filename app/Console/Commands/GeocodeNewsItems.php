<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Services\GeocodingService;
use App\Services\GeocodingException;
use App\Services\GeocodingRateLimitedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Geocode news items that have a canonical_place_name.
 * Skips category_only items cleanly.
 * Bounded retry for rate limits.
 */
class GeocodeNewsItems extends Command
{
    protected $signature = 'ingest:geocode
        {--news_item_id= : Process a specific news item}
        {--limit=50     : Max items to process per run}';

    protected $description = 'Geocode canonical_place_name → lat/lng (skips category_only items)';

    private const MAX_RATE_LIMIT_RETRIES = 2;
    private const RATE_LIMIT_SLEEP_SECS  = 5;

    private GeocodingService $geocoder;

    public function __construct()
    {
        parent::__construct();
        $this->geocoder = new GeocodingService();
    }

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');
        $limit     = (int) $this->option('limit');

        $query = NewsItem::query()
            ->whereNotNull('canonical_place_name')
            ->where('alias_match_status', 'matched')
            ->where(function ($q) {
                // Not yet geocoded, or previously failed (allow retry)
                $q->whereNull('geocode_status')
                  ->orWhereNotIn('geocode_status', ['success']);
            })
            ->where('relevance_mode', '!=', 'category_only')  // explicit skip
            ->orderBy('id');

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        }

        $items = $query->limit($limit)->get();
        $this->info("Geocoding: {$items->count()} items (provider={$this->geocoder->getProvider()}).");

        $rateLimited = 0;
        foreach ($items as $item) {
            $result = $this->geocodeItem($item);
            if ($result === 'rate_limited') $rateLimited++;
            // Stop if we've hit too many rate limits in a row
            if ($rateLimited >= 3) {
                $this->warn('Stopping: too many consecutive rate-limit responses.');
                break;
            }
        }

        $this->info('Done.');
        return 0;
    }

    private function geocodeItem(NewsItem $item): string
    {
        $placeName = $item->canonical_place_name;

        // ── Skip category_only items explicitly ─────────────────────────
        if ($item->relevance_mode === 'category_only') {
            $item->update([
                'geocode_status' => 'skipped_category_only',
                'geocoded_at'    => now(),
            ]);
            $this->line("  SKIP-cat_only {$item->id}: {$placeName}");
            return 'skipped';
        }

        // ── Already geocoded successfully ────────────────────────────────
        if ($item->geocode_status === 'success') {
            $this->line("  SKIP-done {$item->id}: already geocoded");
            return 'skipped';
        }

        $retries = 0;
        $lastException = null;

        while ($retries <= self::MAX_RATE_LIMIT_RETRIES) {
            try {
                $result = $this->geocoder->geocode($placeName);

                $item->update([
                    'latitude'           => $result['lat'],
                    'longitude'          => $result['lng'],
                    'geocode_status'     => 'success',
                    'geocode_provider'   => $this->geocoder->getProvider(),
                    'geocode_confidence' => $result['confidence'],
                    'geocoded_at'        => now(),
                ]);

                $this->line("  OK {$item->id} | {$placeName} → [{$result['lat']},{$result['lng']}]");
                Log::info('Geocode success', [
                    'news_item_id' => $item->id,
                    'place'        => $placeName,
                    'lat'          => $result['lat'],
                    'lng'          => $result['lng'],
                ]);
                return 'success';

            } catch (GeocodingRateLimitedException $e) {
                $retries++;
                $lastException = $e;
                Log::warning('Geocode rate limited', [
                    'news_item_id' => $item->id,
                    'attempt'     => $retries,
                    'place'       => $placeName,
                ]);
                if ($retries > self::MAX_RATE_LIMIT_RETRIES) break;
                $sleep = self::RATE_LIMIT_SLEEP_SECS * $retries;
                $this->warn("  RATE-LIMITED {$item->id}: sleeping {$sleep}s before retry...");
                sleep($sleep);

            } catch (GeocodingException $e) {
                $lastException = $e;
                break; // Don't retry non-rate-limit errors
            }
        }

        // ── Failed after retries ─────────────────────────────────────────
        $item->update([
            'geocode_status'  => $lastException instanceof GeocodingRateLimitedException ? 'rate_limited' : 'failed',
            'geocode_provider'=> $this->geocoder->getProvider(),
            'geocoded_at'     => now(),
        ]);

        $this->warn("  FAILED {$item->id}: {$placeName} — " . $lastException?->getMessage());
        Log::warning('Geocode failed', [
            'news_item_id' => $item->id,
            'place'        => $placeName,
            'error'        => $lastException?->getMessage(),
        ]);
        return 'failed';
    }
}

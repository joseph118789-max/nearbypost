<?php

namespace App\Observers;

use App\Models\NewsItem;
use App\Models\FeedReadyItem;

class NewsItemObserver
{
    public function created(NewsItem $newsItem): void
    {
        // No-op: feed_ready_items population is handled by PopulateFeedReady command
        // after pipeline enrichment completes. Do not write un-enriched data on create.
    }

    public function updated(NewsItem $newsItem): void
    {
        $this->syncToFeed($newsItem);
    }

    public function deleted(NewsItem $newsItem): void
    {
        FeedReadyItem::where('news_item_id', $newsItem->id)->delete();
    }

    public function restored(NewsItem $newsItem): void
    {
        $this->syncToFeed($newsItem);
    }

    public function forceDeleted(NewsItem $newsItem): void
    {
        FeedReadyItem::where('news_item_id', $newsItem->id)->delete();
    }

    private function syncToFeed(NewsItem $newsItem): void
    {
        if ($newsItem->status !== 'active') {
            FeedReadyItem::where('news_item_id', $newsItem->id)->delete();
            return;
        }

        // Use AI-enriched category if available, otherwise fall back to RSS primary_category
        $primaryCategory = $newsItem->ai_category ?: $newsItem->primary_category;

        FeedReadyItem::updateOrCreate(
            ['news_item_id' => $newsItem->id],
            [
                'title' => $newsItem->title,
                'summary' => $newsItem->summary,
                'source' => $newsItem->source,
                'url' => $newsItem->url,
                'published_at' => $newsItem->published_at,
                'primary_category' => $primaryCategory,
                'secondary_category' => $newsItem->secondary_category,
                'sub_category' => $newsItem->sub_category,
                'location_label' => $newsItem->location_label
                    ?: $newsItem->canonical_place_name,
                // latitude/longitude is what the geocoder writes and what
                // PopulateFeedReady serves from; lat/lng is the legacy pair
                // kept only as a fallback for rows geocoded before May 2026.
                'lat' => $newsItem->latitude ?? $newsItem->lat,
                'lng' => $newsItem->longitude ?? $newsItem->lng,
                'canonical_place_name' => $newsItem->canonical_place_name,
                'geo_confidence_score' => $newsItem->geo_confidence_score,
                'coverage_type' => $newsItem->coverage_type,
                'sort_timestamp' => $newsItem->published_at,
                'precision_type' => $newsItem->precision_type,
                'relevance_mode' => $newsItem->relevance_mode,
                'is_active' => true,
            ]
        );
    }
}

<?php

namespace App\Observers;

use App\Models\NewsItem;
use App\Models\FeedReadyItem;
use App\Services\DuplicateGuard;

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
        $this->forgetCommunity($newsItem);
    }

    /**
     * A reader's report deleted from the admin desk (3 Sep 2026: four demo
     * reports) left its meta, comments, reactions, versions and checks behind
     * as orphans. They go with it. The contributor's reputation ledger stays:
     * it is their history, not the report's.
     */
    private function forgetCommunity(NewsItem $newsItem): void
    {
        if (($newsItem->origin ?? null) !== 'user') {
            return;
        }

        $id = $newsItem->id;
        $commentIds = \Illuminate\Support\Facades\DB::table('community_comments')->where('news_item_id', $id)->pluck('id');

        if ($commentIds->isNotEmpty()) {
            foreach (['community_comment_translations', 'community_comment_reports'] as $t) {
                \Illuminate\Support\Facades\DB::table($t)->whereIn('comment_id', $commentIds)->delete();
            }
        }

        foreach (['community_comments', 'community_reactions', 'community_reports', 'community_corrections', 'community_post_versions',
                  'community_status_history', 'community_moderation_checks', 'community_appeals', 'community_post_meta'] as $t) {
            try {
                \Illuminate\Support\Facades\DB::table($t)->where('news_item_id', $id)->delete();
            } catch (\Throwable) {
                // a table this install does not have
            }
        }
    }

    public function restored(NewsItem $newsItem): void
    {
        $this->syncToFeed($newsItem);
    }

    public function forceDeleted(NewsItem $newsItem): void
    {
        FeedReadyItem::where('news_item_id', $newsItem->id)->delete();
        $this->forgetCommunity($newsItem);
    }

    private function syncToFeed(NewsItem $newsItem): void
    {
        if ($newsItem->status !== 'active') {
            FeedReadyItem::where('news_item_id', $newsItem->id)->delete();
            return;
        }

        // Wait for classification before a story's first appearance.
        //
        // PopulateFeedReady gates on enrichment; this observer did not, so a
        // story reached the feed at ingestion time wearing the default category
        // 'others' and kept it until enrichment caught up. Those are the newest
        // stories on the site, so they are the ones a reader sees first.
        //
        // Updates to a story already in the feed still flow immediately, so a
        // re-classification or correction is never delayed.
        $alreadyServed = FeedReadyItem::where('news_item_id', $newsItem->id)->exists();

        if (!$alreadyServed && !in_array($newsItem->ai_status, ['success', 'fallback_used'], true)) {
            return;
        }

        // Same check the bulk path makes: is this story already being served
        // from another source? Only asked on a story's first appearance, so a
        // later correction to an already-served story is never blocked.
        if (!$alreadyServed) {
            $guard = new DuplicateGuard();
            $twin  = $guard->findPublished($newsItem->title, (string) $newsItem->published_at, $newsItem->id);

            if ($twin) {
                $incoming = (object) [
                    'source'  => $newsItem->source,
                    'summary' => $newsItem->ai_summary ?: $newsItem->summary,
                ];

                if (!$guard->preferIncoming($incoming, $twin)) {
                    return;
                }

                FeedReadyItem::where('id', $twin->id)->update(['is_active' => false]);
            }
        }

        // A multi-point story has one serving row per place, written by the
        // case-study panel. updateOrCreate below keys on news_item_id alone, so
        // letting it run here would quietly collapse twenty places into one on
        // the next save. Withdrawal is handled above and still applies.
        if ($newsItem->is_multi_point) {
            return;
        }

        // Use AI-enriched category if available, otherwise fall back to RSS primary_category
        $primaryCategory = $newsItem->ai_category ?: $newsItem->primary_category;

        FeedReadyItem::updateOrCreate(
            ['news_item_id' => $newsItem->id],
            [
                'title' => $newsItem->ai_title ?: $newsItem->title,
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
                // Who wrote it, and the picture if they attached one. The
                // reader's Source filter reads origin, so it has to survive
                // the trip into the serving layer.
                'origin' => $newsItem->origin ?: 'scraper',
                'image_path' => $newsItem->image_path,
                'is_active' => true,
            ]
        );
    }
}

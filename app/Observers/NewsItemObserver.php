<?php

namespace App\Observers;

use App\Models\NewsItem;
use App\Models\FeedReadyItem;

class NewsItemObserver
{
    public function created(NewsItem $newsItem): void
    {
        $this->syncToFeed($newsItem);
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

        FeedReadyItem::updateOrCreate(
            ['news_item_id' => $newsItem->id],
            [
                'title' => $newsItem->title,
                'summary' => $newsItem->summary,
                'source' => $newsItem->source,
                'url' => $newsItem->url,
                'published_at' => $newsItem->published_at,
                'primary_category' => $newsItem->primary_category,
                'secondary_category' => $newsItem->secondary_category,
                'location_label' => $newsItem->location_label,
                'lat' => $newsItem->lat,
                'lng' => $newsItem->lng,
                'precision_type' => $newsItem->precision_type,
                'relevance_mode' => $newsItem->relevance_mode,
                'is_active' => true,
            ]
        );
    }
}

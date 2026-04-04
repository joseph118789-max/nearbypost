<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\RelevanceScore;
use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CalculateRelevanceScores extends Command
{
    protected $signature = 'feed:calculate-scores
        {--news_item_id= : Score a specific item for all subscribers}
        {--subscriber_id= : Score all items for a specific subscriber}
        {--limit=100     : Max items per run}';

    protected $description = 'Calculate and store relevance scores for feed personalisation';

    public function handle(): int
    {
        $newsItemId    = $this->option('news_item_id');
        $subscriberId  = $this->option('subscriber_id');
        $limit         = (int) $this->option('limit');

        if ($newsItemId) {
            $this->scoreForItem((int) $newsItemId);
        } elseif ($subscriberId) {
            $this->scoreForSubscriber((int) $subscriberId);
        } else {
            $this->scoreGlobal($limit);
        }

        return 0;
    }

    /** Score one item against all active subscribers */
    private function scoreForItem(int $newsItemId): void
    {
        $item = NewsItem::findOrFail($newsItemId);
        $subscribers = Subscriber::where('status', 'active')->get();

        foreach ($subscribers as $sub) {
            $this->upsertScore($item, $sub);
        }
        $this->info("Scored item {$newsItemId} for {$subscribers->count()} subscribers.");
    }

    /** Score all items for one subscriber */
    private function scoreForSubscriber(int $subscriberId): void
    {
        $sub = Subscriber::findOrFail($subscriberId);
        $items = NewsItem::where('status', 'active')
            ->whereNotNull('published_at')
            ->orderBy('published_at', 'desc')
            ->limit(200)
            ->get();

        foreach ($items as $item) {
            $this->upsertScore($item, $sub);
        }
        $this->info("Scored {$items->count()} items for subscriber {$subscriberId}.");
    }

    /** Score recent items for all active subscribers */
    private function scoreGlobal(int $limit): void
    {
        $items = NewsItem::where('status', 'active')
            ->whereNotNull('published_at')
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();

        $subscribers = Subscriber::where('status', 'active')->get();

        $count = 0;
        foreach ($items as $item) {
            foreach ($subscribers as $sub) {
                $this->upsertScore($item, $sub);
                $count++;
            }
        }

        $this->info("Global scoring: {$items->count()} items × {$subscribers->count()} subscribers = {$count} scores.");
    }

    private function upsertScore(NewsItem $item, Subscriber $sub): void
    {
        $scores = RelevanceScore::calculate($item, $sub);

        RelevanceScore::updateOrCreate(
            ['news_item_id' => $item->id, 'subscriber_id' => $sub->id],
            array_merge($scores, [
                'calculation_version' => RelevanceScore::VERSION,
                'calculated_at'       => now(),
            ])
        );
    }
}

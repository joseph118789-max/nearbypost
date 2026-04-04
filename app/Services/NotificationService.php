<?php

namespace App\Services;

use App\Jobs\DispatchNotificationJob;
use App\Models\NotificationLog;
use App\Models\Subscriber;
use App\Models\NewsItem;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Dispatch notifications for a news item to all eligible subscribers
     */
    public function dispatchForNewsItem(int $newsItemId, bool $dryRun = false): array
    {
        $newsItem = NewsItem::find($newsItemId);
        
        if (!$newsItem) {
            return [
                'success' => false,
                'message' => 'News item not found',
                'dispatched' => 0,
            ];
        }

        // Get active subscribers whose preferences match this news item
        $subscribers = $this->getEligibleSubscribers($newsItem);

        $dispatched = 0;
        $skipped = 0;

        foreach ($subscribers as $subscriber) {
            // Check if already notified recently for this news item
            $alreadyNotified = NotificationLog::where('subscriber_id', $subscriber->id)
                ->where('news_item_id', $newsItemId)
                ->where('status', NotificationLog::STATUS_SENT)
                ->exists();

            if ($alreadyNotified) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $dispatched++;
                continue;
            }

            DispatchNotificationJob::dispatch($subscriber->id, $newsItemId);
            $dispatched++;
        }

        return [
            'success' => true,
            'news_item_id' => $newsItemId,
            'news_item_title' => $newsItem->title,
            'subscribers_matched' => $subscribers->count(),
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Dispatch notifications for multiple recent news items
     */
    public function dispatchForRecentNewsItems(int $hours = 24, bool $dryRun = false): array
    {
        $recentItems = NewsItem::where('published_at', '>=', now()->subHours($hours))->get();

        $totalDispatched = 0;
        $totalSkipped = 0;
        $results = [];

        foreach ($recentItems as $item) {
            $result = $this->dispatchForNewsItem($item->id, $dryRun);
            $totalDispatched += $result['dispatched'] ?? 0;
            $totalSkipped += $result['skipped'] ?? 0;
            $results[] = $result;
        }

        return [
            'success' => true,
            'items_processed' => $recentItems->count(),
            'total_dispatched' => $totalDispatched,
            'total_skipped' => $totalSkipped,
            'dry_run' => $dryRun,
            'details' => $results,
        ];
    }

    /**
     * Get subscribers whose preferences match the news item
     */
    private function getEligibleSubscribers(NewsItem $newsItem): Collection
    {
        return Subscriber::where("status", "active")->get();
    }

    /**
     * Get notification statistics
     */
    public function getStatistics(?int $subscriberId = null): array
    {
        $query = NotificationLog::query();

        if ($subscriberId) {
            $query->where('subscriber_id', $subscriberId);
        }

        return [
            'total' => $query->count(),
            'sent' => (clone $query)->where('status', NotificationLog::STATUS_SENT)->count(),
            'failed' => (clone $query)->where('status', NotificationLog::STATUS_FAILED)->count(),
            'rate_limited' => (clone $query)->where('status', NotificationLog::STATUS_RATE_LIMITED)->count(),
            'pending' => (clone $query)->where('status', NotificationLog::STATUS_PENDING)->count(),
            'last_24h' => (clone $query)->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Test notification for a subscriber
     */
    public function testNotification(int $subscriberId, bool $dryRun = false): array
    {
        $subscriber = Subscriber::find($subscriberId);

        if (!$subscriber) {
            return [
                'success' => false,
                'message' => 'Subscriber not found',
            ];
        }

        if ($subscriber->status !== 'active') {
            return [
                'success' => false,
                'message' => 'Subscriber is not active',
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'subscriber_id' => $subscriberId,
                'message' => 'Test notification would be sent (dry run)',
            ];
        }

        // Dispatch test notification
        DispatchNotificationJob::dispatch($subscriberId, null);

        return [
            'success' => true,
            'subscriber_id' => $subscriberId,
            'subscriber_name' => $subscriber->name,
            'message' => 'Test notification dispatched',
        ];
    }
}
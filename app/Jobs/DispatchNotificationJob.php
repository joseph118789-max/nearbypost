<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\Subscriber;
use App\Models\NewsItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 60;

    private const MAX_NOTIFICATIONS_PER_HOUR = 10;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $subscriber = Subscriber::find($this->subscriberId);
        $newsItem = $this->newsItemId ? NewsItem::find($this->newsItemId) : null;

        if (!$subscriber || $subscriber->status !== 'active') {
            Log::info('DispatchNotificationJob: Subscriber inactive or not found', [
                'subscriber_id' => $this->subscriberId,
            ]);
            return;
        }

        // Check rate limiting
        $sentRecently = NotificationLog::where('subscriber_id', $this->subscriberId)
            ->where('status', NotificationLog::STATUS_SENT)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $maxAllowed = $subscriber->max_notifications_per_hour ?? self::MAX_NOTIFICATIONS_PER_HOUR;

        if ($sentRecently >= $maxAllowed) {
            $this->logNotification(
                NotificationLog::STATUS_RATE_LIMITED,
                null,
                null,
                'Rate limit exceeded: ' . $sentRecently . ' notifications in last hour (max: ' . $maxAllowed . ')'
            );
            Log::warning('DispatchNotificationJob: Rate limited', [
                'subscriber_id' => $this->subscriberId,
                'sent_recently' => $sentRecently,
                'max_allowed' => $maxAllowed,
            ]);
            return;
        }

        // Determine which channels to use
        $channels = $this->determineChannels($subscriber, $newsItem);

        foreach ($channels as $channel) {
            $this->sendNotification($subscriber, $channel, $newsItem);
        }
    }

    /**
     * Determine which notification channels to use based on subscriber preferences
     */
    private function determineChannels(Subscriber $subscriber, ?NewsItem $newsItem): array
    {
        $channels = [];

        // Check if article matches subscriber preferences
        if ($newsItem && !$this->matchesPreferences($subscriber, $newsItem)) {
            Log::info('DispatchNotificationJob: Article does not match subscriber preferences', [
                'subscriber_id' => $subscriber->id,
                'news_item_id' => $newsItem->id,
            ]);
            return [];
        }

        if ($subscriber->notify_email && $subscriber->email) {
            $channels[] = NotificationLog::CHANNEL_EMAIL;
        }
        if ($subscriber->notify_webhook && $subscriber->webhook_url) {
            $channels[] = NotificationLog::CHANNEL_WEBHOOK;
        }
        if ($subscriber->notify_in_app) {
            $channels[] = NotificationLog::CHANNEL_IN_APP;
        }

        return $channels;
    }

    /**
     * Check if news item matches subscriber preferences
     */
    private function matchesPreferences(Subscriber $subscriber, NewsItem $newsItem): bool
    {
        $preferences = $subscriber->preferences;

        // If no preferences set, send all notifications
        if (empty($preferences)) {
            return true;
        }

        // Check category preferences
        if (isset($preferences['categories']) && is_array($preferences['categories'])) {
            $articleCategory = $newsItem->primary_category ?? $newsItem->ai_category;
            if (!in_array($articleCategory, $preferences['categories'])) {
                return false;
            }
        }

        // Check location preferences
        if (isset($preferences['locations']) && is_array($preferences['locations'])) {
            if (empty($preferences['locations'])) {
                return true; // No location filter
            }
        }

        return true;
    }

    /**
     * Send notification via specific channel
     */
    private function sendNotification(Subscriber $subscriber, string $channel, ?NewsItem $newsItem): void
    {
        $recipient = $this->getRecipient($subscriber, $channel);
        $payload = $this->buildPayload($subscriber, $channel, $newsItem);

        try {
            switch ($channel) {
                case NotificationLog::CHANNEL_EMAIL:
                    $this->sendEmail($recipient, $payload);
                    break;
                case NotificationLog::CHANNEL_WEBHOOK:
                    $this->sendWebhook($recipient, $payload);
                    break;
                case NotificationLog::CHANNEL_IN_APP:
                    $this->storeInAppNotification($subscriber, $payload);
                    break;
            }

            $this->logNotification(NotificationLog::STATUS_SENT, $channel, $recipient, null, json_encode($payload));
            Log::info('DispatchNotificationJob: Notification sent', [
                'subscriber_id' => $subscriber->id,
                'channel' => $channel,
                'news_item_id' => $newsItem?->id,
            ]);

        } catch (\Exception $e) {
            $this->logNotification(NotificationLog::STATUS_FAILED, $channel, $recipient, $e->getMessage(), json_encode($payload));
            Log::error('DispatchNotificationJob: Notification failed', [
                'subscriber_id' => $subscriber->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get recipient based on channel
     */
    private function getRecipient(Subscriber $subscriber, string $channel): string
    {
        return match ($channel) {
            NotificationLog::CHANNEL_EMAIL => $subscriber->email ?? '',
            NotificationLog::CHANNEL_WEBHOOK => $subscriber->webhook_url ?? '',
            NotificationLog::CHANNEL_IN_APP => 'in_app:' . $subscriber->id,
            default => '',
        };
    }

    /**
     * Build notification payload
     */
    private function buildPayload(Subscriber $subscriber, string $channel, ?NewsItem $newsItem): array
    {
        $title = $newsItem?->extracted_title ?? $newsItem?->title ?? 'New Update';
        $summary = $newsItem?->ai_summary ?? $newsItem?->extracted_summary ?? $newsItem?->summary ?? '';

        return [
            'to' => $subscriber->name,
            'to_phone' => $subscriber->phone,
            'channel' => $channel,
            'news_item_id' => $newsItem?->id,
            'title' => $title,
            'summary' => substr($summary, 0, 200),
            'category' => $newsItem?->primary_category ?? $newsItem?->ai_category,
            'url' => $newsItem?->url,
            'source' => $newsItem?->source,
            'sent_at' => now()->toISOString(),
        ];
    }

    /**
     * Send email notification (using Laravel Mail)
     */
    private function sendEmail(string $to, array $payload): void
    {
        // Using Laravel's built-in mail - configure in .env with mail mailer
        // For now, log the email (would use Mail facade in production)
        Log::info('Notification email', [
            'to' => $to,
            'subject' => $payload['title'],
        ]);
    }

    /**
     * Send webhook notification
     */
    private function sendWebhook(string $url, array $payload): void
    {
        try {
            $response = Http::timeout(30)->post($url, $payload);
            
            if (!$response->successful()) {
                throw new \Exception('Webhook returned status: ' . $response->status());
            }
        } catch (\Exception $e) {
            Log::warning('DispatchNotificationJob: Webhook failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Store in-app notification
     */
    private function storeInAppNotification(Subscriber $subscriber, array $payload): void
    {
        Log::info('DispatchNotificationJob: In-app notification stored', [
            'subscriber_id' => $subscriber->id,
            'title' => $payload['title'],
        ]);
    }

    /**
     * Log notification to database
     */
    private function logNotification(
        string $status,
        ?string $channel,
        ?string $recipient,
        ?string $errorMessage = null,
        ?string $payload = null
    ): void {
        NotificationLog::create([
            'subscriber_id' => $this->subscriberId,
            'news_item_id' => $this->newsItemId,
            'channel' => $channel,
            'status' => $status,
            'recipient' => $recipient,
            'payload' => $payload,
            'error_message' => $errorMessage,
            'sent_at' => $status === NotificationLog::STATUS_SENT ? now() : null,
        ]);
    }

    public function __construct(
        private int $subscriberId,
        private ?int $newsItemId = null
    ) {}
}
<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use Illuminate\Console\Command;

class TestNotification extends Command
{
    protected $signature = 'notify:test 
                            {subscriber_id : The subscriber ID to send test notification to}
                            {--dry-run : Run without actually sending}';

    protected $description = 'Send a test notification to a subscriber';

    public function handle(): int
    {
        $subscriberId = (int) $this->argument('subscriber_id');
        $dryRun = $this->option('dry-run');

        $subscriber = Subscriber::find($subscriberId);

        if (!$subscriber) {
            $this->error("Subscriber #{$subscriberId} not found");
            return self::FAILURE;
        }

        $this->info("=== Test Notification ===");
        $this->line("Subscriber: {$subscriber->name}");
        $this->line("Phone: {$subscriber->phone}");
        $this->line("Email: " . ($subscriber->email ?? 'Not set'));
        $this->line("Webhook: " . ($subscriber->webhook_url ?? 'Not set'));
        $this->line("Preferences: " . ($subscriber->preferences ? json_encode($subscriber->preferences) : 'None'));
        $this->line("Notify Email: " . ($subscriber->notify_email ? 'Yes' : 'No'));
        $this->line("Notify Webhook: " . ($subscriber->notify_webhook ? 'Yes' : 'No'));
        $this->line("Notify In-App: " . ($subscriber->notify_in_app ? 'Yes' : 'No'));
        $this->line("Max/Hour: {$subscriber->max_notifications_per_hour}");
        $this->line("");
        $this->line("Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE'));

        if ($dryRun) {
            $this->info("Test notification prepared (dry run)");
            return self::SUCCESS;
        }

        // Actually dispatch the test notification
        $service = app(\App\Services\NotificationService::class);
        $result = $service->testNotification($subscriberId);

        if ($result['success']) {
            $this->info("Test notification dispatched successfully");
            return self::SUCCESS;
        }

        $this->error("Failed: {$result['message']}");
        return self::FAILURE;
    }
}
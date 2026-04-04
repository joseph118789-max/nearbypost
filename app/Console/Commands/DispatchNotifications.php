<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class DispatchNotifications extends Command
{
    protected $signature = 'notify:dispatch 
                            {--news_item_id= : Specific news item ID to dispatch notifications for}
                            {--hours= : Process news items from the last N hours (default: 24)}
                            {--dry-run : Run without actually dispatching notifications}';

    protected $description = 'Dispatch notifications to subscribers based on news items and preferences';

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');
        $hours = (int) $this->option('hours') ?? 24;
        $dryRun = $this->option('dry-run');

        $this->info('=== Notification Dispatcher ===');
        $this->line('Mode: ' . ($dryRun ? 'DRY RUN (no notifications will be sent)' : 'LIVE'));
        
        $service = app(NotificationService::class);

        if ($newsItemId) {
            // Dispatch for specific news item
            $this->info("Processing news item #{$newsItemId}...");
            $result = $service->dispatchForNewsItem((int) $newsItemId, $dryRun);

            $this->showResult($result);

            return $result['success'] ? self::SUCCESS : self::FAILURE;
        }

        // Dispatch for recent news items
        $this->info("Processing news items from last {$hours} hours...");
        $result = $service->dispatchForRecentNewsItems($hours, $dryRun);

        $this->line('');
        $this->info("=== Summary ===");
        $this->line("Items processed: {$result['items_processed']}");
        $this->line("Notifications dispatched: {$result['total_dispatched']}");
        $this->line("Skipped (already notified): {$result['total_skipped']}");
        $this->line("Dry run: " . ($result['dry_run'] ? 'Yes' : 'No'));

        return self::SUCCESS;
    }

    private function showResult(array $result): void
    {
        $this->line('');
        $this->info("=== Result ===");
        
        if ($result['success']) {
            $this->line("News item: {$result['news_item_title']}");
            $this->line("Subscribers matched: {$result['subscribers_matched']}");
            $this->line("Dispatched: {$result['dispatched']}");
            $this->line("Skipped: {$result['skipped']}");
            $this->line("Dry run: " . ($result['dry_run'] ? 'Yes' : 'No'));
        } else {
            $this->error("Error: {$result['message']}");
        }
    }
}
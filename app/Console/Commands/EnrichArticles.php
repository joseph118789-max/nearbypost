<?php

namespace App\Console\Commands;

use App\Jobs\EnrichArticleJob;
use App\Models\NewsItem;
use Illuminate\Console\Command;

class EnrichArticles extends Command
{
    protected $signature = 'ingest:enrich {--news_item_id= : Process a specific news item by ID}';
    protected $description = 'Run AI enrichment on pending news items';

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');

        if ($newsItemId) {
            // Process single item
            $newsItem = NewsItem::find($newsItemId);
            
            if (!$newsItem) {
                $this->error("News item #{$newsItemId} not found");
                return self::FAILURE;
            }

            $this->info("Enriching news item #{$newsItemId}: {$newsItem->title}");
            EnrichArticleJob::dispatch($newsItemId);
            
            $this->info("Job dispatched for news item #{$newsItemId}");
            return self::SUCCESS;
        }

        // Batch process pending items
        $pendingItems = NewsItem::where('ai_status', 'pending')
            ->orWhereNull('ai_status')
            ->limit(10)
            ->get();

        if ($pendingItems->isEmpty()) {
            $this->info("No pending items for AI enrichment");
            return self::SUCCESS;
        }

        $this->info("Processing {$pendingItems->count()} pending items for AI enrichment");

        foreach ($pendingItems as $item) {
            EnrichArticleJob::dispatch($item->id);
            $this->line("Dispatched job for item #{$item->id}");
        }

        $this->info("All jobs dispatched successfully");
        return self::SUCCESS;
    }
}
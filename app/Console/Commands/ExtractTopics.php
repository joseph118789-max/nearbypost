<?php

namespace App\Console\Commands;

use App\Jobs\ExtractTopicsJob;
use App\Models\NewsItem;
use Illuminate\Console\Command;

class ExtractTopics extends Command
{
    protected $signature = 'ingest:extract-topics 
                            {--news_item_id= : Process a specific news item ID}
                            {--limit=10 : Maximum items to process}
                            {--status= : Filter by ai_status (pending, processing, success, failed)}';

    protected $description = 'Extract topic intelligence and named entities from articles using AI';

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');
        $limit = (int) $this->option('limit');
        $status = $this->option('status');

        if ($newsItemId) {
            // Process specific item
            $newsItem = NewsItem::find($newsItemId);
            
            if (!$newsItem) {
                $this->error("NewsItem with ID {$newsItemId} not found.");
                return Command::FAILURE;
            }
            
            $this->info("Processing NewsItem #{$newsItemId}: {$newsItem->title}");
            ExtractTopicsJob::dispatchSync($newsItemId);
            
            $this->info("Entity extraction complete for NewsItem #{$newsItemId}");
            return Command::SUCCESS;
        }

        // Batch process feed-ready items
        $query = NewsItem::whereNotNull('extracted_text')
            ->orWhereNotNull('summary')
            ->orWhereNotNull('title');

        if ($status) {
            $query->where('ai_status', $status);
        } else {
            // Default: process items without existing entities
            $query->whereDoesntHave('topicEntities');
        }

        $items = $query->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            $this->info("No items found for topic extraction.");
            return Command::SUCCESS;
        }

        $this->info("Found " . $items->count() . " items for topic extraction.");

        $processed = 0;
        $failed = 0;

        foreach ($items as $item) {
            $this->line("Processing #{$item->id}: " . substr($item->title, 0, 60));
            
            try {
                ExtractTopicsJob::dispatchSync($item->id);
                $processed++;
            } catch (\Exception $e) {
                $this->error("Failed: " . $e->getMessage());
                $failed++;
            }
        }

        $this->info("Processed: {$processed}, Failed: {$failed}");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
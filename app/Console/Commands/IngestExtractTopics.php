<?php

namespace App\Console\Commands;

use App\Jobs\ExtractTopicsJob;
use App\Models\NewsItem;
use App\Models\FeedReadyItem;
use Illuminate\Console\Command;

class IngestExtractTopics extends Command
{
    protected $signature = 'ingest:extract-topics {--news_item_id= : Process a specific news item ID}
        {--limit=10 : Number of items to process}
        {--status=feed_ready : Filter by news item status}
        {--dry-run : Show what would be processed without processing}';

    protected $description = 'Extract topic entities and named entities from feed-ready news items';

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        // If specific ID provided
        if ($newsItemId) {
            $newsItem = NewsItem::find($newsItemId);
            
            if (!$newsItem) {
                $this->error("News item #{$newsItemId} not found.");
                return Command::FAILURE;
            }

            $this->info("Processing news item #{$newsItemId}: {$newsItem->title}");

            if (!$dryRun) {
                ExtractTopicsJob::dispatch($newsItemId);
                $this->info("Job dispatched successfully.");
            }

            return Command::SUCCESS;
        }

        // Get feed-ready items that haven't been processed
        $query = NewsItem::whereHas('feedReadyItem')
            ->where(function ($q) {
                $q->whereNull('topic_extraction_status')
                  ->orWhereNot('topic_extraction_status', 'success');
            });

        $count = $query->count();

        if ($count === 0) {
            $this->info('No feed-ready items need topic extraction.');
            
            // Show items that have been processed
            $processed = NewsItem::where('topic_extraction_status', 'success')->count();
            $this->info("Already processed: {$processed} items.");
            
            return Command::SUCCESS;
        }

        $this->info("Found {$count} items needing topic extraction.");

        $items = $query->limit($limit)->get(['id', 'title', 'source', 'published_at']);

        foreach ($items as $item) {
            $this->line("  - [{$item->id}] {$item->title}");
        }

        if ($dryRun) {
            $this->warn("\nDry run - no jobs dispatched.");
            return Command::SUCCESS;
        }

        $this->info("\nDispatching extraction jobs...");

        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        foreach ($items as $item) {
            ExtractTopicsJob::dispatch($item->id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Dispatched {$items->count()} extraction jobs.");

        return Command::SUCCESS;
    }
}
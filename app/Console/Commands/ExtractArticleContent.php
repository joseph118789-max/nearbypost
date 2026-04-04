<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\ExtractionJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ExtractArticleContent extends Command
{
    protected $signature = 'ingest:extract {--news_item_id=}';
    protected $description = 'Extract content from news items';

    public function handle()
    {
        $newsItemId = $this->option('news_item_id');

        $query = NewsItem::whereDoesntHave('extractionJob', function ($q) {
            $q->whereIn('extraction_status', ['success', 'fallback_used']);
        });

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        }

        $items = $query->whereNotNull('url')->limit(10)->get();

        $this->info("Processing {$items->count()} items.");

        foreach ($items as $item) {
            $this->processItem($item);
        }
    }

    private function processItem(NewsItem $item)
    {
        $job = ExtractionJob::create([
            'news_item_id' => $item->id,
            'extraction_status' => 'pending',
        ]);

        try {
            // Try trafilatura first
            $extracted = $this->extractWithTrafilatura($item->url);

            if ($extracted && !empty($extracted['text'])) {
                $job->update([
                    'extracted_title' => $extracted['title'] ?? $item->title,
                    'extracted_summary' => $extracted['summary'] ?? $item->summary,
                    'extracted_text' => $extracted['text'],
                    'extraction_status' => 'success',
                    'extraction_method' => 'trafilatura',
                    'extracted_at' => now(),
                ]);
                $this->info("✓ Extracted (trafilatura): {$item->id}");
            } else {
                $this->useFallback($item, $job);
            }
        } catch (\Exception $e) {
            $this->useFallback($item, $job, $e->getMessage());
        }
    }

    private function extractWithTrafilatura(string $url): ?array
    {
        // Use trafilatura via CLI
        $cmd = "python3 -c \"import trafilatura; result = trafilatura.fetch_url('{$url}'); print(result) if result else print('NONE')\"";
        $output = shell_exec($cmd);

        if (!$output || trim($output) === 'NONE' || trim($output) === 'None') {
            return null;
        }

        $cmd2 = "python3 -c \"import trafilatura, sys; html=sys.stdin.read(); meta=trafilatura.extract(html); print(meta) if meta else print('NONE')\"";
        $text = shell_exec($cmd2);

        return [
            'title' => null,
            'summary' => null,
            'text' => trim($text) ?: null,
        ];
    }

    private function useFallback(NewsItem $item, ExtractionJob $job, string $error = null)
    {
        // Fallback order: feed summary → cleaned summary → minimal preserved raw summary
        $summary = $item->summary;

        $job->update([
            'extracted_title' => $item->title,
            'extracted_summary' => $summary,
            'extracted_text' => $summary, // use summary as text fallback
            'extraction_status' => $summary ? 'fallback_used' : 'failed',
            'extraction_method' => $summary ? 'feed_summary_fallback' : 'failed',
            'extracted_at' => now(),
        ]);

        if ($error) {
            Log::warning('Extraction fallback triggered', [
                'news_item_id' => $item->id,
                'error' => $error,
            ]);
        }

        $this->warn("↪ Fallback used for {$item->id}" . ($error ? " ({$error})" : ''));
    }
}

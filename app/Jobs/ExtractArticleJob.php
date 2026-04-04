<?php

namespace App\Jobs;

use App\Models\NewsItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ExtractArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // 60 seconds initial backoff
    public int $timeout = 120;

    private const FALLBACK_ORDER = [
        'trafilatura' => 'Extract using trafilatura',
        'readability' => 'Extract using readability-lxml',
        'feed_fallback' => 'Use feed-provided summary/description',
        'raw_summary' => 'Use minimal preserved summary',
    ];

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $newsItem = NewsItem::find($this->newsItemId);
        
        if (!$newsItem) {
            Log::warning('ExtractArticleJob: News item not found', ['id' => $this->newsItemId]);
            return;
        }

        Log::info('extraction.start', [
            'news_item_id' => $newsItem->id,
            'url' => $newsItem->url,
        ]);

        $result = $this->extractWithFallback($newsItem);

        // Update news item with extraction results
        $newsItem->update([
            'extracted_title' => $result['title'],
            'extracted_summary' => $result['summary'],
            'extracted_text' => $result['text'],
            'extraction_status' => $result['status'],
            'extraction_method' => $result['method'],
            'extracted_at' => now(),
        ]);

        Log::info('extraction.complete', [
            'news_item_id' => $newsItem->id,
            'status' => $result['status'],
            'method' => $result['method'],
            'text_length' => $result['text'] ? strlen($result['text']) : 0,
        ]);

        // Dispatch AI enrichment job if extraction succeeded
        if (in_array($result['status'], ['success', 'fallback_used'])) {
            // Dispatch in background for async processing
            // DispatchAsyncEnrichmentJob::dispatch($newsItem->id);
        }
    }

    /**
     * Try extraction methods in fallback order
     */
    private function extractWithFallback(NewsItem $newsItem): array
    {
        // Try each method in order
        $methods = ['trafilatura', 'readability'];

        foreach ($methods as $method) {
            try {
                $result = $this->{"extractVia{$method}"}($newsItem);
                
                // Check if we got meaningful content
                if (!empty($result['text']) || !empty($result['summary'])) {
                    return [
                        'title' => $result['title'] ?? $newsItem->title,
                        'summary' => $result['summary'] ?? $newsItem->summary,
                        'text' => $result['text'] ?? null,
                        'status' => 'success',
                        'method' => $method,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("extraction.{$method}_failed", [
                    'news_item_id' => $newsItem->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // Fallback to feed summary/description
        if (!empty($newsItem->summary)) {
            return [
                'title' => $newsItem->title,
                'summary' => $newsItem->summary,
                'text' => null,
                'status' => 'fallback_used',
                'method' => 'feed_fallback',
            ];
        }

        // Final fallback - minimal raw summary
        return [
            'title' => $newsItem->title,
            'summary' => $newsItem->title, // At least something
            'text' => null,
            'status' => 'fallback_used',
            'method' => 'raw_summary',
        ];
    }

    /**
     * Extract using trafilatura (requires package)
     */
    private function extractViaTrafilatura(NewsItem $newsItem): array
    {
        // Try to use trafilatura if available
        if (!class_exists('\Trafilatura\Pipeline')) {
            throw new \Exception('Trafilatura not installed');
        }

        // Fetch the URL and extract
        $response = Http::timeout(30)->get($newsItem->url);
        
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch URL: ' . $response->status());
        }

        $html = $response->body();
        
        // Use trafilatura to extract
        $result = \Trafilatura\Pipeline::run($html, [
            'output_format' => 'json',
            'with_images' => false,
            'with_tables' => false,
        ]);

        if ($result) {
            return [
                'title' => $result['title'] ?? null,
                'summary' => $result['excerpt'] ?? null,
                'text' => $result['text'] ?? null,
            ];
        }

        throw new \Exception('Trafilatura returned empty result');
    }

    /**
     * Extract using readability-lxml
     */
    private function extractViaReadability(NewsItem $newsItem): array
    {
        if (!class_exists('\Arc05cl\Readability\Readability')) {
            throw new \Exception('Readability not installed');
        }

        $response = Http::timeout(30)->get($newsItem->url);
        
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch URL: ' . $response->status());
        }

        $html = $response->body();
        
        $readability = new \Arc05cl\Readability\Readability($html);
        $result = $readability->parse();

        return [
            'title' => $result['title'] ?? null,
            'summary' => $result['excerpt'] ?? null,
            'text' => $result['content'] ?? null,
        ];
    }

    public function __construct(
        private int $newsItemId
    ) {}
}
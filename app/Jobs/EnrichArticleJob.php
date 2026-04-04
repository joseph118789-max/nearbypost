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

class EnrichArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120; // 2 minutes initial backoff
    public int $timeout = 180;

    private const PROMPT_VERSION = 'v1';

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $newsItem = NewsItem::find($this->newsItemId);
        
        if (!$newsItem) {
            Log::warning('EnrichArticleJob: News item not found', ['id' => $this->newsItemId]);
            return;
        }

        // Check idempotency - skip if already processed with same version
        if ($newsItem->ai_status === 'success' && $newsItem->ai_prompt_version === self::PROMPT_VERSION) {
            Log::info('EnrichArticleJob: Skipping - already processed', [
                'id' => $this->newsItemId,
                'prompt_version' => $newsItem->ai_prompt_version,
            ]);
            return;
        }

        Log::info('ai_enrichment.start', [
            'news_item_id' => $newsItem->id,
            'url' => $newsItem->url,
        ]);

        // Update status to processing
        $newsItem->update(['ai_status' => 'processing']);

        try {
            // Prepare input from extracted layer
            $input = [
                'title' => $newsItem->extracted_title ?? $newsItem->title,
                'source' => $newsItem->source,
                'published_at' => $newsItem->published_at?->toISOString(),
                'category' => $newsItem->primary_category,
                'summary' => $newsItem->extracted_summary ?? $newsItem->summary,
                'text' => $newsItem->extracted_text,
            ];

            // Call AI API
            $result = $this->callAiApi($input);

            // Calculate token and cost estimates
            $tokensIn = $this->estimateTokens(json_encode($input));
            $tokensOut = $this->estimateTokens(json_encode($result));
            $estimatedCost = $this->calculateCost($tokensIn, $tokensOut);

            // Update news item with AI results
            $newsItem->update([
                'ai_summary' => $result['summary'] ?? null,
                'ai_category' => $result['category'] ?? $newsItem->primary_category,
                'main_place_text' => $result['main_place_text'] ?? null,
                'relevance_mode' => $result['relevance_mode'] ?? 'category_only',
                'ai_status' => 'success',
                'ai_processed_at' => now(),
                'ai_model' => $this->model,
                'ai_prompt_version' => self::PROMPT_VERSION,
                'ai_tokens_in' => $tokensIn,
                'ai_tokens_out' => $tokensOut,
                'ai_estimated_cost' => $estimatedCost,
            ]);

            Log::info('ai_enrichment.success', [
                'news_item_id' => $newsItem->id,
                'category' => $result['category'],
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'estimated_cost' => $estimatedCost,
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->handleFailure($newsItem, 'network_error: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->handleFailure($newsItem, 'ai_error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Call AI API - configure actual endpoint in .env
     */
    private function callAiApi(array $input): array
    {
        $apiKey = config('services.openai.key');
        $model = config('services.openai.model', 'gpt-4o-mini');
        
        if (empty($apiKey)) {
            // Fallback: return basic enriched data without API call
            Log::warning('EnrichArticleJob: No AI API key configured, using fallback');
            return $this->fallbackEnrichment($input);
        }

        $prompt = $this->buildPrompt($input);

        try {
            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a news analyzer. Return ONLY valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 1000,
                'temperature' => 0.3,
            ]);

            if (!$response->successful()) {
                throw new \Exception('AI API error: ' . $response->status());
            }

            $result = json_decode($response['choices'][0]['message']['content'], true);
            return $result ?? $this->fallbackEnrichment($input);
            
        } catch (\Exception $e) {
            Log::warning('AI API call failed, using fallback', ['error' => $e->getMessage()]);
            return $this->fallbackEnrichment($input);
        }
    }

    /**
     * Fallback enrichment without AI API
     */
    private function fallbackEnrichment(array $input): array
    {
        return [
            'summary' => substr($input['summary'] ?? $input['title'] ?? '', 0, 200),
            'category' => $input['category'] ?? 'others',
            'main_place_text' => null,
            'relevance_mode' => 'category_only',
        ];
    }

    /**
     * Build prompt for AI
     */
    private function buildPrompt(array $input): string
    {
        return <<<PROMPT
Analyze this news article and provide:
1. A 2-3 sentence summary
2. Primary category (property, transport, crime, sports, business, government, education, health, lifestyle, community, environment, technology, entertainment, jobs, others)
3. Main place/location mentioned (if any)
4. Relevance mode: location_only, category_only, or hybrid

Article:
Title: {$input['title']}
Source: {$input['source']}
Category: {$input['category']}
Summary: {$input['summary']}

Respond as JSON only: {"summary":"...","category":"...","main_place_text":"...","relevance_mode":"..."}
PROMPT;
    }

    /**
     * Estimate token count
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Calculate estimated cost
     */
    private function calculateCost(int $tokensIn, int $tokensOut): float
    {
        $pricePer1kInput = 0.00015;
        $pricePer1kOutput = 0.0006;
        return round(($tokensIn * $pricePer1kInput + $tokensOut * $pricePer1kOutput) / 1000, 6);
    }

    /**
     * Handle failure
     */
    private function handleFailure(NewsItem $newsItem, string $reason): void
    {
        $newsItem->update(['ai_status' => 'failed']);

        Log::error('ai_enrichment.failed', [
            'news_item_id' => $newsItem->id,
            'reason' => $reason,
        ]);
    }

    public function __construct(
        private int $newsItemId,
        private string $model = 'gpt-4o-mini'
    ) {}
}
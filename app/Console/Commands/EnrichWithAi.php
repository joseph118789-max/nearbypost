<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\AiProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class EnrichWithAi extends Command
{
    protected $signature = 'ingest:enrich
        {--news_item_id= : Process a specific news item}
        {--force : Re-process even if already successfully processed}';

    protected $description = 'Enrich news items with AI: validates output, enforces controlled enums, preserves good output on retry';

    // ── Versioning ───────────────────────────────────────────────────────────
    private const PROMPT_VERSION    = 'v2';
    private const PIPELINE_VERSION  = 'v1.0';
    private const MODEL             = 'deepseek-chat';
    private const MAX_RETRIES       = 2;

    // ── Controlled enums ────────────────────────────────────────────────────
    private const RELEVANCE_MODES = ['category_only', 'location_only', 'location_and_category'];
    private const VALID_CATEGORIES = [
        'technology','politics','business','sports','entertainment',
        'health','science','world','local','other',
    ];

    // ── Validation thresholds ───────────────────────────────────────────────
    private const MIN_SUMMARY_LEN  = 10;
    private const MAX_SUMMARY_LEN  = 1000;
    private const MAX_PLACE_LEN    = 200;

    public function handle(): int
    {
        $apiKey = config('services.deepseek.key');
        if (!$apiKey) {
            $this->error('DeepSeek API key not configured');
            return 1;
        }

        $force       = $this->option('force');
        $newsItemId  = $this->option('news_item_id');

        $query = NewsItem::whereHas('extractionJob', function ($q) {
            $q->whereIn('extraction_status', ['success', 'fallback_used']);
        });

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        }

        if (!$force) {
            // Skip items that already have a successful AI result for current pipeline version
            $query->whereDoesntHave('aiProcessingJob', function ($q) {
                $q->where('ai_status', 'success')
                  ->where('pipeline_version', self::PIPELINE_VERSION);
            });
        }

        $items = $query->limit(5)->get();
        $this->info("AI Enrichment pipeline=" . self::PIPELINE_VERSION . " | processing {$items->count()} items.");

        foreach ($items as $item) {
            $this->processItem($item, $apiKey, $force);
        }

        return 0;
    }

    private function processItem(NewsItem $item, string $apiKey, bool $force): void
    {
        // ── Idempotency: preserve previously good output ─────────────────
        if (!$force) {
            $existing = AiProcessingJob::where('news_item_id', $item->id)
                ->where('ai_status', 'success')
                ->where('pipeline_version', self::PIPELINE_VERSION)
                ->first();
            if ($existing) {
                $this->line("  SKIP {$item->id}: already processed, good output preserved.");
                return;
            }
        }

        // Create or reuse a job record
        $job = AiProcessingJob::create([
            'news_item_id'      => $item->id,
            'ai_status'        => 'pending',
            'prompt_version'   => self::PROMPT_VERSION,
            'pipeline_version' => self::PIPELINE_VERSION,
            'model_used'       => self::MODEL,
        ]);

        $extraction = $item->extractionJob;
        $inputText  = $extraction->extracted_text
                  ?? $extraction->extracted_summary
                  ?? $item->summary
                  ?? '';
        $title      = $extraction->extracted_title ?? $item->title;

        $prompt = $this->buildPrompt($title, $inputText, $item->source ?? '');
        $retries = 0;
        $lastException = null;

        while ($retries <= self::MAX_RETRIES) {
            try {
                $response = $this->callOpenAi($apiKey, $prompt);
                $rawOutput = $response['raw_content'] ?? '';
                $parsed    = $this->parseAiResponse($rawOutput);

                // ── Validate before saving ──────────────────────────────
                $validation = $this->validateAiOutput($parsed, $rawOutput);

                if (!$validation['valid']) {
                    $job->update([
                        'ai_status'       => 'invalid_output',
                        'raw_ai_output'   => $rawOutput,
                        'validation_notes' => implode('; ', $validation['errors']),
                        'processed_at'    => now(),
                    ]);
                    $this->warn("  INVALID {$item->id}: " . implode(', ', $validation['errors']));
                    Log::warning('AI enrichment invalid output', [
                        'news_item_id' => $item->id,
                        'errors'       => $validation['errors'],
                        'raw_output'   => substr($rawOutput, 0, 200),
                    ]);
                    return; // Don't retry invalid output — it's not a transient error
                }

                // ── All good: persist validated fields ────────────────────
                $job->update([
                    'ai_status'          => 'success',
                    'raw_ai_output'      => $rawOutput,
                    'validated_summary'  => $validation['summary'],
                    'validated_category' => $validation['category'],
                    'validated_place'    => $validation['place'],
                    'relevance_mode'     => $validation['relevance_mode'],
                    'main_place_text'    => $validation['place'],
                    'tokens_in'          => $response['usage']['prompt_tokens'] ?? null,
                    'tokens_out'         => $response['usage']['completion_tokens'] ?? null,
                    'estimated_cost'      => $this->estimateCost($response),
                    'processed_at'       => now(),
                ]);

                $this->info("  OK {$item->id} | mode={$validation['relevance_mode']} | cat={$validation['category']}");
                Log::info('AI enrichment success', [
                    'news_item_id'   => $item->id,
                    'pipeline'       => self::PIPELINE_VERSION,
                    'model'          => self::MODEL,
                    'relevance_mode' => $validation['relevance_mode'],
                    'category'       => $validation['category'],
                    'tokens_in'      => $response['usage']['prompt_tokens'] ?? null,
                    'tokens_out'     => $response['usage']['completion_tokens'] ?? null,
                ]);
                return;

            } catch (\Exception $e) {
                $lastException = $e;
                $retries++;
                Log::warning('AI enrichment retry', [
                    'news_item_id' => $item->id,
                    'attempt'     => $retries,
                    'error'       => $e->getMessage(),
                ]);
                if ($retries > self::MAX_RETRIES) break;
                sleep(2);
            }
        }

        // ── All retries exhausted ───────────────────────────────────────
        $fallbackUsed = $this->applyFallback($job, $extraction, $item);
        $job->update([
            'ai_status'    => $fallbackUsed ? 'fallback_used' : 'failed',
            'error_message' => $lastException ? substr($lastException->getMessage(), 0, 500) : 'Unknown error',
            'processed_at' => now(),
        ]);
        $label = $fallbackUsed ? "FALLBACK" : "FAILED";
        $this->warn("  {$label} {$item->id}" . ($lastException ? ": {$lastException->getMessage()}" : ''));
    }

    /**
     * Validate and normalise AI output.
     * Returns ['valid' => bool, 'summary' => string, 'category' => string,
     *          'place' => string|null, 'relevance_mode' => string, 'errors' => string[]]
     */
    private function validateAiOutput(array $parsed, string $rawOutput): array
    {
        $errors = [];

        // 1. summary
        $summary = trim($parsed['summary'] ?? '');
        if (strlen($summary) < self::MIN_SUMMARY_LEN) {
            $errors[] = "summary_too_short:" . strlen($summary);
        } elseif (strlen($summary) > self::MAX_SUMMARY_LEN) {
            $summary = substr($summary, 0, self::MAX_SUMMARY_LEN);
        }
        if (empty($summary)) {
            $errors[] = 'summary_empty';
        }

        // 2. category
        $category = strtolower(trim($parsed['category'] ?? ''));
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            $errors[] = "category_unknown:{$category}";
            $category = 'other'; // coerce to safe default
        }

        // 3. place — allow null/empty (category_only), but if set must be reasonable
        $place = mb_substr(trim($parsed['place'] ?? ''), 0, self::MAX_PLACE_LEN);
        if (!empty($place) && strlen($place) < 2) {
            $place = null; // treat single-char place as empty
        }

        // 4. relevance_mode — coerce to controlled set
        $rawRelevance = strtolower(trim($parsed['relevance'] ?? ''));
        $relevanceMap = [
            'local'   => 'location_and_category',
            'national'=> 'category_only',
            'global'  => 'category_only',
        ];
        if (isset($relevanceMap[$rawRelevance])) {
            $relevanceMode = $relevanceMap[$rawRelevance];
        } elseif (in_array($rawRelevance, self::RELEVANCE_MODES, true)) {
            $relevanceMode = $rawRelevance;
        } else {
            // Infer from presence of place
            $relevanceMode = !empty($place) ? 'location_and_category' : 'category_only';
        }

        if (empty($place) && $relevanceMode === 'location_and_category') {
            // Force consistency: if no place, can't be location mode
            $relevanceMode = 'category_only';
        }

        return [
            'valid'         => empty($errors),
            'summary'       => $summary ?: ($parsed['summary'] ?? ''), // keep raw if passes
            'category'      => $category,
            'place'         => $place,
            'relevance_mode'=> $relevanceMode,
            'errors'        => $errors,
        ];
    }

    /**
     * When AI completely fails, apply rule-based fallback.
     * Preserves whatever was extracted; does NOT pollute with bad AI data.
     */
    private function applyFallback(AiProcessingJob $job, $extraction, NewsItem $item): bool
    {
        $extractedCategory = $item->primary_category ?? null;
        $extractedPlace    = $extraction->extracted_summary ?? null;

        if (!$extractedCategory && !$extractedPlace) {
            return false; // Nothing to fall back on
        }

        $job->update([
            'ai_status'          => 'fallback_used',
            'validated_category' => $extractedCategory ?: 'other',
            'validated_summary'  => mb_substr($extractedPlace ?? $item->summary ?? '', 0, 500),
            'validated_place'    => null,
            'relevance_mode'     => !empty($extractedPlace) ? 'location_only' : 'category_only',
            'main_place_text'    => null,
            'raw_ai_output'      => null,
            'validation_notes'   => 'Fallback: AI unavailable, rule-based values applied',
        ]);

        Log::info('AI enrichment fallback applied', [
            'news_item_id' => $item->id,
            'fallback_category' => $extractedCategory,
        ]);

        return true;
    }

    private function buildPrompt(string $title, string $text, string $source): string
    {
        $truncated = mb_substr($text, 0, 3000);
        // Escape for safe embedding in prompt
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeSrc   = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');
        $safeText  = htmlspecialchars($truncated, ENT_NOQUOTES, 'UTF-8');

        return <<<PROMPT
You are a precise news analyst. Given the article below, respond with ONLY valid JSON — no markdown fences, no explanation.

Return this exact shape:
{
  "summary": "2-3 sentence summary of the article (10-300 chars)",
  "category": "one of: technology, politics, business, sports, entertainment, health, science, world, local, other",
  "place": "main specific location (city or state in Malaysia preferred, or null if not location-specific)",
  "relevance": "location_and_category if place is a specific city/area, category_only if national/world-wide"
}

Article title: {$safeTitle}
Source: {$safeSrc}
Content:
{$safeText}
PROMPT;
    }

    private function callDeepSeek(string $apiKey, string $prompt): array
    {
        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.deepseek.com/v1/chat/completions', [
                'model'    => self::MODEL,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.3,
            ]);

        if (!$response->successful()) {
            throw new \Exception('DeepSeek API error: ' . $response->status() . ' - ' . $response->body());
        }

        $body = $response->json();
        if (!isset($body['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid DeepSeek response: missing content field');
        }
        $body['raw_content'] = $body['choices'][0]['message']['content'];
        return $body;
    }

    private function parseAiResponse(string $rawContent): array
    {
        $content = preg_replace('/^```json\s*/', '', $rawContent);
        $content = preg_replace('/^```\s*/', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to extract first JSON object
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $parsed = json_decode($matches[0], true);
            }
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Could not parse AI response as JSON: ' . json_last_error_msg());
            }
        }

        return [
            'summary'   => $parsed['summary']   ?? null,
            'category'  => $parsed['category']  ?? null,
            'place'     => $parsed['place']     ?? null,
            'relevance' => $parsed['relevance']  ?? 'category_only',
        ];
    }

    private function estimateCost(array $response): string
    {
        $in  = $response['usage']['prompt_tokens'] ?? 0;
        $out = $response['usage']['completion_tokens'] ?? 0;
        $cost = ($in * 0.15 / 1_000_000) + ($out * 0.60 / 1_000_000);
        return number_format($cost, 6, '.', '');
    }
}

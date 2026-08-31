<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\AiProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\SubCategoryTaxonomy;

/**
 * ═══════════════════════════════════════════════════════════════
 * D11 AUTHORITATIVE AI ENRICHMENT PIPELINE
 * ═══════════════════════════════════════════════════════════════
 *
 * This is the ONE authoritative D11 implementation.
 * All AI enrichment MUST flow through this command.
 *
 * Run via: php artisan ingest:enrich
 *
 * Architectural guarantees:
 * - Prompt + pipeline versioning (PROMPT_VERSION, PIPELINE_VERSION)
 * - AiProcessingJob tracking for audit/replay
 * - Controlled enums for categories and relevance modes
 * - Validated output only — invalid responses are rejected, not coerced
 * - Preserves good prior output on retry (idempotent, skip-known-good)
 * - Failover to fallback_used status when AI is unavailable
 *
 * Legacy alternative: app/Jobs/EnrichArticleJob.php — DO NOT USE
 * That job is blocked (fail-fast) and logs CRITICAL if dispatched.
 *
 * ═══════════════════════════════════════════════════════════════
 */

class EnrichWithAi extends Command
{
    protected $signature = 'ingest:enrich
        {--news_item_id= : Process a specific news item}
        {--force : Re-process even if already processed}
        {--limit=50 : Number of items to process per run}';

    protected $description = 'Enrich news items with AI: validates output, enforces controlled enums, preserves good output on retry';

    // ── Versioning ───────────────────────────────────────────────────────────
    private const PROMPT_VERSION    = 'v4';
    private const PIPELINE_VERSION  = 'v1.0';
    private const MODEL             = 'deepseek-chat';
    private const MAX_RETRIES       = 2;

    // ── Controlled enums ────────────────────────────────────────────────────
    private const RELEVANCE_MODES = ['category_only', 'location_only', 'location_and_category'];

    /** The languages a reader can choose. Summaries are produced in all three. */
    private const READING_LOCALES = ['en', 'ms', 'zh'];
    private const VALID_CATEGORIES = [
        'Property & Real Estate',
        'Food & Lifestyle',
        'Infrastructure',
        'Transport & Mobility',
        'Crime & Safety',
        'Environment',
        'Education',
        'Health',
        'Travel',
        'Entertainment / Arts & Culture',
        'Charity & Nonprofits',
        'Weather',
        'Defense & Military',
        'Markets & Finance',
        'Business & Corporate',
        'Technology & Digital',
        'Automotive',
        'Government & Policy',
        'Science',
        'Sports',
        'Religion',
        'other',
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

        // Prioritize items that are known to need processing (ai_status = pending)
        $query = NewsItem::whereHas('extractionJob', function ($q) {
            $q->whereIn('extraction_status', ['success', 'fallback_used']);
        });

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        } else {
            // Normal run must be conservative: only process rows explicitly marked pending.
            // Do NOT automatically resend previously processed/backfilled items to DeepSeek.
            // Do NOT reprocess earlier successful pipeline versions unless --force is used.
            // Do NOT auto-retry insufficient_content in scheduled runs.
            $query->where('ai_status', 'pending')
                  ->whereDoesntHave('aiProcessingJob', function ($q2) {
                      $q2->where('ai_status', 'success');
                  });
        }

        $limit = (int) ($this->option('limit') ?: 50);
        $items = $query->limit($limit)->get();
        $this->info("AI Enrichment pipeline=" . self::PIPELINE_VERSION . " | processing {$items->count()} items (limit={$limit}).");

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
        // Use extracted_text, fall back to extracted_summary, then item summary
        $rawText = $extraction->extracted_text ?? '';
        if (empty($rawText) || strtoupper($rawText) === 'NONE') {
            $rawText = $extraction->extracted_summary ?? $item->summary ?? '';
        }
        $inputText = $rawText;
        $title      = $extraction->extracted_title ?? $item->title;

        $prompt = $this->buildPrompt($title, $inputText, $item->source ?? '');
        $retries = 0;
        $lastException = null;

        while ($retries <= self::MAX_RETRIES) {
            try {
                $response = $this->callDeepSeek($apiKey, $prompt);
                $rawOutput = $response['raw_content'] ?? '';
                $parsed    = $this->parseAiResponse($rawOutput);

                // ── Validate before saving ──────────────────────────────
                $validation = $this->validateAiOutput($parsed, $rawOutput);

                if (!$validation['valid']) {
                    $notes = implode('; ', $validation['errors']);
                    $job->update([
                        'ai_status'        => 'insufficient_content',
                        'raw_ai_output'    => $rawOutput,
                        'validation_notes' => $notes,
                        'processed_at'     => now(),
                    ]);
                    $item->update([
                        'ai_status'                 => 'insufficient_content',
                        'ai_processed_at'           => now(),
                        'enrichment_failure_reason' => $notes,
                        'enrichment_failure_count'  => (int) ($item->enrichment_failure_count ?? 0) + 1,
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
                    'is_article'         => $validation['is_article'] ?? true,
                    'main_place_text'    => $validation['place'],
                    'lat'                => $validation['lat'],
                    'lng'                => $validation['lng'],
                    'tokens_in'          => $response['usage']['prompt_tokens'] ?? null,
                    'tokens_out'         => $response['usage']['completion_tokens'] ?? null,
                    'estimated_cost'      => $this->estimateCost($response),
                    'processed_at'       => now(),
                ]);

                // Update NewsItem with coordinates if available and set status
                $updateData = [
                    'main_place_text'            => $validation['place'],
                    'ai_category'                => $validation['category'],
                    'sub_category'               => $validation['sub_category'],
                    'source_language'            => $validation['source_language'],
                    'ai_summary'                 => $validation['summary'],
                    'ai_status'                  => 'success',
                    'ai_processed_at'            => now(),
                    'enrichment_failure_reason'  => null,
                ];
                if ($validation['lat'] !== null && $validation['lng'] !== null) {
                    $updateData['lat'] = $validation['lat'];
                    $updateData['lng'] = $validation['lng'];
                }
                if ($validation['is_article'] ?? true) {
                    $relevanceMode = $validation['relevance_mode'] ?? 'category_only';
                    $lat = $validation['lat'] ?? null;
                    $lng = $validation['lng'] ?? null;
                    // Malaysia bounding box: lat 0.5-7.5, lng 99.5-120
                    $isMalaysia = $lat !== null && $lng !== null
                        && $lat >= 0.5 && $lat <= 7.5
                        && $lng >= 99.5 && $lng <= 120;
                    if ($relevanceMode === 'category_only' || !$isMalaysia) {
                        $updateData['status'] = 'international';
                    } else {
                        $updateData['status'] = 'active';
                    }
                    $updateData['relevance_mode'] = $relevanceMode;
                }

                $item->update($updateData);

                $this->storeTranslations($item, $validation['translations'] ?? []);

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
     *          'place' => string|null, 'relevance_mode' => string, 'is_article' => bool, 'lat' => float|null, 'lng' => float|null, 'errors' => string[]]
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

        // 2. category — case-insensitive match against VALID_CATEGORIES
        $rawCat = trim($parsed['category'] ?? '');
        $category = strtolower($rawCat);
        // Build lowercase version of VALID_CATEGORIES for comparison
        $validLower = array_map('strtolower', self::VALID_CATEGORIES);
        if (!in_array($category, $validLower, true)) {
            $errors[] = "category_unknown:{$rawCat}";
            $category = 'other'; // coerce to safe default
        }

        // 3. place — allow null/empty (category_only), but if set must be reasonable
        $place = mb_substr(trim($parsed['place'] ?? ''), 0, self::MAX_PLACE_LEN);
        if (!empty($place) && strlen($place) < 2) {
            $place = null; // treat single-char place as empty
        }

        // 4. relevance_mode — coerce to controlled set
        $rawRelevance = strtolower(trim($parsed['relevance'] ?? ''));

        // 4b. is_article — coerce to boolean (default true for backward compat)
        $isArticle = isset($parsed['is_article']) ? (bool) $parsed['is_article'] : true;

        // 4c. lat/lng — validate coordinates if present
        $lat = isset($parsed['lat']) && is_numeric($parsed['lat']) ? (float) $parsed['lat'] : null;
        $lng = isset($parsed['lng']) && is_numeric($parsed['lng']) ? (float) $parsed['lng'] : null;
        // Validate coordinate ranges
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $lat = null;
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $lng = null;
        }
        // If either coord is missing/invalid, clear both
        if ($lat === null || $lng === null) {
            $lat = null;
            $lng = null;
        }
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

        // Spec s10: the sub-category must belong to the chosen primary;
        // anything else is forced to that primary's "Others".
        $subCategory = (new SubCategoryTaxonomy())->validate(
            $rawCat !== '' ? $rawCat : $category,
            $parsed['sub_category'] ?? null
        );

        // Keep only well-formed translations. A locale missing or empty falls
        // back to the original text at read time rather than showing nothing.
        $translations = [];

        foreach (self::READING_LOCALES as $locale) {
            $candidate = $parsed['t'][$locale] ?? null;

            if (!is_array($candidate)) {
                continue;
            }

            $translatedTitle = trim((string) ($candidate['title'] ?? ''));

            if ($translatedTitle === '') {
                continue;
            }

            $translations[$locale] = [
                'title'   => mb_substr($translatedTitle, 0, 550),
                'summary' => mb_substr(trim((string) ($candidate['summary'] ?? '')), 0, 1200) ?: null,
            ];
        }

        $sourceLanguage = preg_match('/^[a-z]{2}$/', (string) ($parsed['lang'] ?? ''))
            ? $parsed['lang']
            : null;

        return [
            'valid'         => empty($errors),
            'summary'       => $summary ?: ($parsed['summary'] ?? ''), // keep raw if passes
            'category'      => $category,
            'sub_category'  => $subCategory,
            'translations'  => $translations,
            'source_language' => $sourceLanguage,
            'place'         => $place,
            'relevance_mode'=> $relevanceMode,
            'is_article'    => $isArticle,
            'lat'           => $lat,
            'lng'           => $lng,
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
            'is_article'         => true,
            'raw_ai_output'      => null,
            'validation_notes'   => 'Fallback: AI unavailable, rule-based values applied',
        ]);

        Log::info('AI enrichment fallback applied', [
            'news_item_id' => $item->id,
            'fallback_category' => $extractedCategory,
        ]);

        return true;
    }

    /**
     * Upsert this story's translations.
     *
     * Written after the item itself so a translation failure can never lose the
     * classification work that came with it.
     */
    private function storeTranslations(NewsItem $item, array $translations): void
    {
        if ($translations === []) {
            return;
        }

        foreach ($translations as $locale => $text) {
            \Illuminate\Support\Facades\DB::table('news_translations')->updateOrInsert(
                ['news_item_id' => $item->id, 'locale' => $locale],
                [
                    'title'      => $text['title'],
                    'summary'    => $text['summary'],
                    'model'      => self::MODEL,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $item->update(['translated_at' => now()]);
    }

    private function buildPrompt(string $title, string $text, string $source): string
    {
        $truncated = mb_substr($text, 0, 3000);
        // Escape for safe embedding in prompt
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeSrc   = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');
        $safeText  = htmlspecialchars($truncated, ENT_NOQUOTES, 'UTF-8');
        $taxonomy  = (new SubCategoryTaxonomy())->promptBlock();

        return <<<PROMPT
You are a precise news analyst. Given the article below, respond with ONLY valid JSON — no markdown fences, no explanation.

Return this exact shape:
{
  "is_article": true or false - is this content a genuine news article (true) or just a navigation page, tag page, category listing, or non-content page (false),
  "summary": "2-3 sentence summary of the article (10-300 chars, omit if not an article)",
  "category": "one of: Property & Real Estate, Food & Lifestyle, Infrastructure, Transport & Mobility, Crime & Safety, Environment, Education, Health, Travel, Entertainment / Arts & Culture, Charity & Nonprofits, Weather, Defense & Military, Markets & Finance, Business & Corporate, Technology & Digital, Automotive, Government & Policy, Science, Sports, Religion, other (omit if not an article)",
  "place": "main specific location (city or state in Malaysia preferred, or null if not location-specific or not an article)",
  "lat": "latitude of the place (number, e.g. 3.139, omit/null if not location-specific or cannot determine)",
  "lng": "longitude of the place (number, e.g. 101.687, omit/null if not location-specific or cannot determine)",
  "relevance": "location_and_category if place is a specific city/area, category_only if national/world-wide (omit if not an article)",
  "sub_category": "exact sub-category name copied from the line below that matches your chosen category (omit if not an article)",
  "lang": "ISO 639-1 code of the language the article is written in, e.g. en, ms, zh, ta, hi, ja, ko",
  "t": {
    "en": {"title": "the headline in natural English", "summary": "the summary in natural English"},
    "ms": {"title": "the headline in natural Malay", "summary": "the summary in natural Malay"},
    "zh": {"title": "the headline in Simplified Chinese", "summary": "the summary in Simplified Chinese"}
  }
}

Translate faithfully. Keep place names, people and organisations in the form a
Malaysian reader would recognise; do not translate proper nouns that are
normally left as they are. Do not add anything the article does not say.

Sub-categories by category. Pick one from the line matching the category you chose:
{$taxonomy}

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
            // Whitelist parser: a key omitted here never reaches validation.
            'sub_category' => $parsed['sub_category'] ?? null,
            'lang'         => $parsed['lang'] ?? null,
            't'            => is_array($parsed['t'] ?? null) ? $parsed['t'] : null,
            'place'     => $parsed['place']     ?? null,
            'relevance' => $parsed['relevance']  ?? 'category_only',
            'is_article'=> isset($parsed['is_article']) ? (bool) $parsed['is_article'] : true,
            'lat'       => isset($parsed['lat']) && is_numeric($parsed['lat']) ? (float) $parsed['lat'] : null,
            'lng'       => isset($parsed['lng']) && is_numeric($parsed['lng']) ? (float) $parsed['lng'] : null,
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

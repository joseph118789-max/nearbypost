<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\AiProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\SubCategoryTaxonomy;
use App\Services\Classification\ContentPolicy;
use App\Services\Classification\CategoryScorer;
use App\Services\Classification\BatchSlots;

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
    private const PROMPT_VERSION    = 'v5';
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

        // Spec 4.3/4.4 and 2.6/2.7. Refusing here rather than after the
        // model call is the difference between a policy that costs nothing
        // and one that costs a request per rejected item.
        $policy = new ContentPolicy();

        // Spec 3.6/3.7: an unreadable page falls back to title-only with a
        // capped confidence. It is not a refusal. Only a page we did fetch and
        // found too thin is INSUFFICIENT_CONTENT (spec 4.3).
        $haveArticleBody = ($extraction->extraction_status ?? '') === 'success'
            && str_word_count((string) $extraction->extracted_text) >= 50;

        if ($haveArticleBody && $code = $policy->screenContent($inputText)) {
            $this->refuse($item, $job, $code, 'content screening');
            return;
        }

        // Advisory: a ceiling and the reasons for it, handed to the classifier
        // rather than acted on here. Judging news value with a regex discarded
        // half of all real Malay articles when it was measured.
        $newsValue = $policy->assessNewsValue($title, $inputText, $haveArticleBody);

        // Spec 2.9: the model cannot know what it said about the previous
        // forty-nine items, so the remaining allowance is handed to it.
        $batchSlots = new BatchSlots();
        $slots      = $batchSlots->remaining();

        $prompt = $this->buildPrompt($title, $inputText, $item->source ?? '', $slots);
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

                // ── Spec 2.6: the model may refuse the item outright ──────
                if ($validation['d'] === 1) {
                    $this->refuse($item, $job, $validation['e'], 'classifier discard');
                    return;
                }

                // ── Spec 6/9/10: the arithmetic happens here, not in the model
                $scorer  = new CategoryScorer();
                $outcome = $scorer->score($validation['rel'], $validation['sub'], [
                    'gps'   => $validation['g'] === 1,
                    'cap'   => $haveArticleBody ? $newsValue['cap'] : min($newsValue['cap'], 0.7),
                    'slots' => $slots,
                ]);

                if (!($outcome['valid'] ?? false)) {
                    $retries++;

                    if ($retries <= self::MAX_RETRIES) {
                        $this->warn("  RESCORE {$item->id}: " . ($outcome['reason'] ?? 'unscorable'));
                        continue;
                    }

                    $this->refuse($item, $job, 'VALIDATION_FAILED', 'no scorable categories');
                    return;
                }

                $ambiguous  = $outcome['ambiguous'] || $validation['a'] === 1;
                $confidence = $policy->confidence([
                    'url_used'   => $haveArticleBody,
                    'word_count' => str_word_count((string) $inputText),
                    'retries'    => $retries,
                    'ambiguous'  => $ambiguous,
                    'non_primary_language' => ($validation['source_language'] ?? 'en') !== 'en',
                    'spam_signals' => $newsValue['reasons'] !== [],
                ]);

                // Spec 17: the production contract.
                $contract = [
                    'g'  => $validation['g'],
                    'd'  => 0,
                    'a'  => $ambiguous ? 1 : 0,
                    'b'  => ($slots['relevance_1_slots'] ?? 1) === 0 ? 1 : 0,
                    'u'  => $haveArticleBody ? 1 : 0,
                    'c'  => $confidence,
                    'e'  => null,
                    'p'  => [$outcome['primary']['id'], $outcome['primary']['relevance'], $outcome['primary']['score']],
                    's'  => $outcome['secondary']
                        ? [$outcome['secondary']['id'], $outcome['secondary']['relevance'], $outcome['secondary']['score']]
                        : null,
                    'sc' => $outcome['sub']['id']
                        ? [$outcome['sub']['id'], $outcome['sub']['relevance'], $outcome['sub']['score']]
                        : null,
                ];

                // ── Spec 13: hard rejection, then retry rather than store ──
                $specErrors = $scorer->validate($contract);

                if ($specErrors !== []) {
                    $retries++;

                    if ($retries <= self::MAX_RETRIES) {
                        $this->warn("  REVALIDATE {$item->id}: " . implode(', ', $specErrors));
                        continue;
                    }

                    $this->refuse($item, $job, 'VALIDATION_FAILED', implode(', ', $specErrors));
                    return;
                }

                $batchSlots->consume($outcome['primary']['relevance']);

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
                    // The scorer's decision, not the model's opinion.
                    'ai_category'                => mb_strtolower($outcome['primary']['name']),
                    'secondary_category'         => $outcome['secondary']
                        ? mb_strtolower($outcome['secondary']['name'])
                        : null,
                    'sub_category'               => $outcome['sub']['name'],
                    'classification'             => json_encode($contract),
                    'meta_confidence'            => $confidence,
                    'ambiguous'                  => $ambiguous,
                    'source_language'            => $validation['source_language'],
                    'discarded'                  => false,
                    'error_code'                 => null,
                    'url_used'                   => $haveArticleBody,
                    'gps_flag'                   => $validation['g'] === 1,
                    'spec_version'               => self::PROMPT_VERSION,
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
        // The model is no longer asked to name a category: it supplies relevance
        // per id and CategoryScorer decides. Only complain when relevance is
        // missing too, which would mean a response in the older shape.
        $hasRelevance = !empty($parsed['rel']);

        if (!$hasRelevance && !in_array($category, $validLower, true)) {
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
        // Spec section 5 answers this with g, so read that rather than the
        // free-text field the v5 prompt no longer asks for. Reading the old
        // field made every story category_only - the one value the Nearby feed
        // excludes - while the contract correctly recorded g:1.
        $gpsFlag = (int) ($parsed['g'] ?? 0) === 1;

        if ($gpsFlag && !empty($place)) {
            $relevanceMode = 'location_and_category';
        } elseif (in_array($rawRelevance, self::RELEVANCE_MODES, true) && empty($parsed['rel'])) {
            // Older-shaped response, before g existed.
            $relevanceMode = $rawRelevance;
        } else {
            $relevanceMode = 'category_only';
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
            // The spec's own fields, passed through untouched for the scorer.
            'rel'           => $parsed['rel'] ?? [],
            'sub'           => $parsed['sub'] ?? [],
            'd'             => (int) ($parsed['d'] ?? 0),
            'e'             => $parsed['e'] ?? null,
            'g'             => (int) ($parsed['g'] ?? 0),
            'a'             => (int) ($parsed['a'] ?? 0),
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

    /**
     * Spec section 8: refuse an item, and record why.
     *
     * A refusal is written down rather than merely skipped. An item that is
     * simply absent cannot be explained later, and this pipeline now accepts
     * publishers nobody has reviewed - the reasons are how that stays
     * accountable.
     */
    private function refuse(NewsItem $item, AiProcessingJob $job, ?string $errorCode, string $why): void
    {
        $job->update([
            'ai_status'        => 'insufficient_content',
            'validation_notes' => 'policy: ' . $why . ($errorCode ? " ({$errorCode})" : ''),
            'processed_at'     => now(),
        ]);

        $item->update([
            'discarded'       => true,
            'error_code'      => $errorCode,
            'meta_confidence' => 0.0,
            'ai_status'       => 'discarded',
            'ai_processed_at' => now(),
            'status'          => 'rejected',
            'spec_version'    => self::PROMPT_VERSION,
        ]);

        $this->warn("  DISCARD {$item->id}: {$why}" . ($errorCode ? " [{$errorCode}]" : ''));

        Log::info('Policy discard', [
            'news_item_id' => $item->id,
            'error_code'   => $errorCode,
            'why'          => $why,
        ]);
    }

    private function buildPrompt(string $title, string $text, string $source, array $slots = []): string
    {
        $truncated = mb_substr($text, 0, 3000);
        // Escape for safe embedding in prompt
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeSrc   = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');
        $safeText  = htmlspecialchars($truncated, ENT_NOQUOTES, 'UTF-8');
        $taxonomy  = (new SubCategoryTaxonomy())->promptBlock();

        $categoryList = (new CategoryScorer())->promptCategories();
        $subList      = (new SubCategoryTaxonomy())->promptBlockWithIds();
        $slots        = json_encode($slots);

        return <<<PROMPT
You are an expert hyperlocal news classifier for nearbypost.com. Respond with
ONLY valid JSON - no markdown fences, no commentary.

You judge RELEVANCE. You do not choose the category: relevance is multiplied by
each category's weight elsewhere, and the highest score wins. Do not try to
predict that outcome.

Return this exact shape:
{
  "is_article": true or false,
  "d": 0 or 1,
  "e": null or one of SPAM_DETECTED, OFF_TOPIC, INSUFFICIENT_CONTENT, INVALID_CONTENT, PAYWALL_BLOCKED, UNSUPPORTED_LANGUAGE,
  "g": 0 or 1,
  "a": 0 or 1,
  "rel": {"<category id>": <relevance 0-1>, ...},
  "sub": {"<sub-category id>": <relevance 0-1>, ...},
  "summary": "2-3 sentence summary of the article",
  "place": "the main specific location, or null if not tied to one place",
  "lang": "ISO 639-1 code of the language the article is written in",
  "t": {
    "en": {"title": "headline in natural English", "summary": "summary in natural English"},
    "ms": {"title": "headline in natural Malay", "summary": "summary in natural Malay"},
    "zh": {"title": "headline in Simplified Chinese", "summary": "summary in Simplified Chinese"}
  }
}

DISCARD (d = 1) if the item is not news: pure opinion or editorial, unconfirmed
rumour, speculation ("might", "could", "possibly"), he-said-she-said with no
resolution, clickbait without substance, or no actual event. Set e when a listed
code applies. When d = 1, rel and sub may be empty.

RELEVANCE
- Include only categories with non-zero relevance. Omit the rest.
- 1.0 is RARE: it needs a specific place, a specific action, and a verifiable
  fact. At most one category may be 1.0.
- At most one further category may be 0.8-0.9. All others 0.6 or below.
- Opinion, speculation or an interview caps everything at 0.6.
- Remaining high-confidence slots in this batch: {$slots}. If a slot is 0 you
  may not use that level; choose the next one down.

GPS (g = 1) only when a specific named place is given - a town, district,
region or street. "Kuala Lumpur" and "KL" qualify. "urban areas", "some areas"
and "city center" without a city do not.

AMBIGUOUS (a = 1) when two categories are genuinely equally applicable.

SUB-CATEGORIES: give relevance for any that apply, from any category - the
correct one for the winning category is selected afterwards.

Categories (id: name):
{$categoryList}

Sub-categories (id: name, grouped by category):
{$subList}

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
            // Whitelist parser: a key omitted here never reaches validation.
            'd'            => (int) ($parsed['d'] ?? 0),
            'e'            => $parsed['e'] ?? null,
            'g'            => (int) ($parsed['g'] ?? 0),
            'a'            => (int) ($parsed['a'] ?? 0),
            'rel'          => is_array($parsed['rel'] ?? null) ? $parsed['rel'] : [],
            'sub'          => is_array($parsed['sub'] ?? null) ? $parsed['sub'] : [],
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

<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\AiProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\SubCategoryTaxonomy;
use App\Services\Classification\ContentPolicy;
use App\Services\Contribution\CaseStudyExamples;
use App\Services\Contribution\ReviewRules;
use App\Services\Ai\AiSpend;
use App\Services\Knowledge\PromptAssembler;
use App\Services\Classification\CategoryScorer;
use App\Services\Geo\DeicticPlace;
use App\Services\Geo\LatinName;
use App\Services\Geo\PlaceScale;
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
        {--stale : Re-judge stories last judged under an older prompt}
        {--limit=50 : Number of items to process per run}
        {--refetch : Re-read the publisher when the stored body was pruned, guarded}';

    protected $description = 'Enrich news items with AI: validates output, enforces controlled enums, preserves good output on retry';

    // ── Versioning ───────────────────────────────────────────────────────────
    private const PROMPT_VERSION    = 'v6';
    private const PIPELINE_VERSION  = 'v1.0';
    private const MODEL             = 'deepseek-chat';

    /** How many stories were waiting when this run started; "share" mode in the AI panel reads it. */
    private int $backlog = 0;

    /** The publisher's country for the story being judged, so its own notes are used. */
    private ?string $enrichCountry = null;
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
    /**
     * More places than this all playing the same part, and the article is a
     * bulletin rather than a story with a location. Matched to the multi-point
     * server's own limit so the two stages cannot disagree.
     */
    private const BULLETIN_PLACES = 6;

    private const MAX_PLACE_LEN    = 200;

    public function handle(): int
    {
        $apiKey = (\App\Services\Ai\AiRouter::for('enrich')->isConfigured() ? 'via-ai-panel' : '');
        if (!$apiKey) {
            $this->error('DeepSeek API key not configured');
            return 1;
        }

        // --stale means re-judge, so it carries its own permission: without
        // this the guard would refuse every item the option just selected.
        $force       = $this->option('force') || $this->option('stale');
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

        // ── --stale: everything the current prompt has never seen ──────────
        //
        // A normal run deliberately refuses to re-send a story that already
        // succeeded, which is right: it stops a scheduled job quietly spending
        // money re-deciding settled questions. But it also means a prompt fix
        // only ever reaches new stories, and the archive keeps whatever the old
        // prompt decided - which is how a dateline fix landed while 96% of the
        // live feed went on showing national stories as news near Kuala Lumpur.
        //
        // So there is a deliberate way to say "apply the new rules to the old
        // stories", separate from the scheduled run and never used by it.
        // Newest first, so a limited batch spends itself on what readers can
        // actually see.
        if ($this->option('stale')) {
            $query = NewsItem::whereHas('extractionJob', function ($q) {
                $q->whereIn('extraction_status', ['success', 'fallback_used']);
            })->whereHas('aiProcessingJob', function ($q) {
                $q->where('ai_status', 'success')
                   ->where('prompt_version', '!=', self::PROMPT_VERSION);
            });
        }

        $limit = (int) ($this->option('limit') ?: 50);
        // Newest first: a stage that cannot clear its backlog should
        // spend its limit on today's news, not on the same old stories
        // that have failed every run for months.
        $this->backlog = (int) (clone $query)->count();
        $items = $query->orderByDesc('published_at')->limit($limit)->get();
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
            // The LATEST job, matching the relation the selecting query uses.
            // Asking for any success ever disagreed with it: an item whose v4
            // run succeeded and whose v5 run then returned insufficient_content
            // passed the query and was refused here, every run, forever - six
            // rows sitting permanently at the head of a newest-first queue,
            // spending six slots of every run on nothing.
            $existing = $item->aiProcessingJob;

            if ($existing && $existing->ai_status === 'success') {
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

        // The body was pruned and we have been asked to go and get it again.
        // Only ever on request: the scheduled run must not quietly start
        // hitting publishers for text it threw away.
        if ($this->option('refetch')
            && empty($extraction->extracted_text)
            && $extraction->original_length > 0) {
            $recovered = $this->refetchBody($item, $extraction);

            if ($recovered === null) {
                // Refused, not failed. Judging a consent stub as though it were
                // the article is worse than leaving the old answer alone.
                $this->warn("  REFUSED {$item->id}: re-fetch did not return the article");

                return;
            }

            $extraction->extracted_text = $recovered;
        }

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
                $this->enrichCountry = \App\Services\Geo\SourceCountry::iso2((string) $item->source);
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
                //
                // ⛔ EXCEPT when it discards for the wrong country while naming
                // a place in ONE OF OURS.
                //
                // Told in the playbook that Singapore is ours, the model agreed
                // for most stories and not for the ones whose PEOPLE are
                // foreign. Measured 4 Sep 2026, three runs each: "Tourist in
                // S'pore chases down man who stole Gucci handbag" was discarded
                // twice in three - while answering place = "Circular Road,
                // Singapore" every single time. It knows where the story
                // happened; it is the relevance verdict that wavers.
                //
                // Saying it again in prose did not fix it - measured, twice.
                // The comment at 3b above reached the same conclusion about a
                // different rule: "the instruction is already in the contract
                // one line above the field; repeating it in prose has not
                // worked, so it is checked here instead."
                //
                // So: a story whose own answer places it in a country we serve
                // is not discarded for lacking an angle on the other one. This
                // cannot rescue a foreign story, because a foreign story names
                // a foreign place or none at all.
                if ($validation['d'] === 1
                    && $validation['e'] === 'NOT_MALAYSIA_RELEVANT'
                    && $this->placeIsInAServedCountry($validation)) {
                    $this->line(sprintf('  discarded for the wrong country, but it happened in %s - kept',
                        mb_substr((string) $validation['place'], 0, 50)));
                } elseif ($validation['d'] === 1) {
                    // Score it anyway. A discarded story still has a subject,
                    // and filing a foreign election under nothing at all makes
                    // the Removed page unreadable.
                    $this->refuse(
                        $item,
                        $job,
                        $validation['e'],
                        'classifier discard',
                        $this->categoryOf($validation)
                    );

                    return;
                }

                // ── Spec 6: no Malaysian angle, no reason to serve it ─────
                //
                // Relevance, not location. A story about Malaysians abroad is
                // kept and kept in the place it happened.
                //
                // ⛔⛔ BUT THE SITE IS NOT ONLY MALAYSIA. SITE_COUNTRIES=MY,SG.
                //
                // The model is asked whether a story has a MALAYSIAN angle. For
                // a Singapore story in a Singapore paper the honest answer is
                // no - and this branch then threw it away. Measured 4 Sep 2026:
                // 680 stories discarded this way, including 101 from The
                // Straits Times, 74 from Channel News Asia, 36 from Tamil
                // Murasu and 30 from Berita Harian Singapura. The Singapore
                // edition was discarding Singapore's news, and the owner found
                // it: "why discard? it says sexual harrassment at bukit batok
                // singapore. its crime located at Bukit botak".
                //
                // A story that belongs to a country we serve is never irrelevant
                // for want of an angle on a DIFFERENT country we serve. Which
                // country it belongs to: the one its place names, and when the
                // place names none, the one the masthead is published in.
                if ($validation['my'] === 0 && $this->servesThisStory($validation, $item)) {
                    $this->line(sprintf('  kept for its own country: %s (%s)',
                        mb_substr((string) ($validation['place'] ?? $item->source), 0, 50), $item->source));
                } elseif ($validation['my'] === 0) {
                    $item->update([
                        'malaysia_relevant' => false,
                        'relevance_reason'  => mb_substr((string) $validation['why'], 0, 110),
                    ]);

                    $this->refuse(
                        $item,
                        $job,
                        'NOT_MALAYSIA_RELEVANT',
                        'no Malaysian angle: ' . mb_substr((string) $validation['why'], 0, 60),
                        // Same reasoning as the classifier discard above: a
                        // foreign election is still politics. Fixing only the
                        // other call site left five discards in a batch of
                        // twenty filed under nothing at all.
                        $this->categoryOf($validation)
                    );

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
                    // Cached input is billed at about a tenth of fresh input, so
                    // the split is the whole story: a total token count no longer
                    // says anything useful about what a call cost.
                    'cache_hit_tokens'   => $response['usage']['prompt_cache_hit_tokens'] ?? null,
                    'cache_miss_tokens'  => $response['usage']['prompt_cache_miss_tokens'] ?? null,
                    'estimated_cost'      => $this->estimateCost($response),
                    'processed_at'       => now(),
                ]);

                // Update NewsItem with coordinates if available and set status
                $updateData = [
                    'main_place_text'            => $validation['place'],
                    // The workings, not just the answer. A location that
                    // cannot be explained cannot be corrected.
                    'place_roles'                => json_encode($validation['places_named'] ?? []),
                    // The scorer's decision, not the model's opinion.
                    'ai_category'                => mb_strtolower($outcome['primary']['name']),

                    // ⛔ WRITTEN TOO, BECAUSE IT WAS LYING. primary_category is
                    // the older column and nothing had updated it since
                    // ai_category was introduced, so it read 'others' on 2,268
                    // of 2,290 stories judged in three days while ai_category
                    // held the real answer. Readers never saw it - populate-feed
                    // resolves the category from the classification JSON - but
                    // applyFallback() read it, ServeMultiPointStories falls back
                    // to it, and any report written against it would have been
                    // quietly wrong. Two columns for one fact is the bug; until
                    // one is dropped, they must agree.
                    'primary_category'           => mb_strtolower($outcome['primary']['name']),
                    'secondary_category'         => $outcome['secondary']
                        ? mb_strtolower($outcome['secondary']['name'])
                        : null,
                    'sub_category'               => $this->subCategoryFor($outcome, $validation),
                    'classification'             => json_encode($contract),
                    'meta_confidence'            => $confidence,
                    'ambiguous'                  => $ambiguous,
                    'source_language'            => $validation['source_language'],
                    'malaysia_relevant'          => true,
                    'relevance_reason'           => mb_substr((string) $validation['why'], 0, 110),
                    'discarded'                  => false,
                    'error_code'                 => null,
                    'url_used'                   => $haveArticleBody,
                    'gps_flag'                   => $validation['g'] === 1,
                    'spec_version'               => self::PROMPT_VERSION,
                    'ai_summary'                 => $validation['summary'],
                    // a headline that merely repeats the publisher's is no headline of ours
                    'ai_title'                   => self::isOwnHeadline($validation['title'] ?? null, $item->title) ? $validation['title'] : null,
                    'ai_status'                  => 'success',
                    'ai_processed_at'            => now(),
                    'enrichment_failure_reason'  => null,
                ];
                if ($validation['lat'] !== null && $validation['lng'] !== null) {
                    $updateData['lat'] = $validation['lat'];
                    $updateData['lng'] = $validation['lng'];
                }

                // ── The geocode belongs to the place text ──────────────────
                //
                // When a re-judgement changes where a story happened - or
                // decides it happened nowhere in particular - everything
                // derived from the old answer is now wrong, and none of it
                // clears itself.
                //
                // ⛔ This was doing real damage. The v6 rules correctly emptied
                // main_place_text on national stories, but canonical_place_name
                // and the coordinates kept the dateline city the older prompt
                // had invented - and PopulateFeedReady reads
                // canonical_place_name, not main_place_text. So an AI training
                // programme for the whole country, a Kedah gambling row and a
                // Melaka election story all went on being served as news near
                // Kuala Lumpur, by a pipeline that had already worked out they
                // were nothing of the kind.
                if ($validation['place'] !== $item->main_place_text) {
                    $updateData['canonical_place_name'] = null;
                    $updateData['location_label']       = null;
                    $updateData['latitude']             = null;
                    $updateData['longitude']            = null;
                    $updateData['geocode_status']       = null;
                    $updateData['geocoded_at']          = null;
                    $updateData['precision_type']       = null;
                    $updateData['alias_match_status']   = null;
                    $updateData['alias_match_type']     = null;

                    // Only the classifier's own reading survives, so the
                    // geocoder starts from the new place rather than agreeing
                    // with the old one.
                    if ($validation['place'] === null) {
                        $updateData['lat'] = null;
                        $updateData['lng'] = null;
                    }
                }
                if ($validation['is_article'] ?? true) {
                    $relevanceMode = $validation['relevance_mode'] ?? 'category_only';

                    // Anything still here has already been judged twice: the
                    // classifier refused what is not news, and the relevance
                    // test refused what has no Malaysian angle. What is left is
                    // worth serving.
                    //
                    // Having no location is not a reason to hide a story. It
                    // decides WHICH feed it appears in - Near Me sorts by
                    // distance and already excludes category_only on its own,
                    // while By Interest and the topic pages do not care. This
                    // line used to read "category_only OR outside a Malaysia
                    // bounding box means international", which hid every
                    // national policy story, profile, market report and
                    // overseas sport result the moment the location rules
                    // started correctly answering "nowhere".
                    //
                    // The bounding box was the crude version of the relevance
                    // question, and the relevance filter now answers it with
                    // nuance - keeping Malaysians caught in the Nepal floods
                    // and refusing flooding at the Grand Canyon. Asking it
                    // again here, worse, only overrode the good answer.
                    $updateData['status'] = 'active';
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
    /** True when the model's headline is more than the publisher's headline in a different case or spacing. */
    public static function isOwnHeadline(?string $own, ?string $original): bool
    {
        if ($own === null || $own === '') {
            return false;
        }

        $norm = fn ($s) => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $s)));

        return $norm($own) !== $norm($original);
    }

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

        // Things we quietly put right, as opposed to things that make the reply
        // unusable. A correction must never invalidate a story: the guard that
        // raises one has already fixed what it is complaining about.
        $corrections = [];

        // 3. place — allow null/empty (category_only), but if set must be reasonable
        $place = mb_substr(trim($parsed['place'] ?? ''), 0, self::MAX_PLACE_LEN);
        if (!empty($place) && strlen($place) < 2) {
            $place = null; // treat single-char place as empty
        }

        // 3b. The answer must be one of the places it marked "happened".
        //
        // The model has twice done the analysis correctly and then ignored it -
        // marking London a dateline and answering London, marking Como an
        // office and answering Como. The instruction is already in the contract
        // one line above the field; repeating it in prose has not worked, so it
        // is checked here instead.

        // 3b-ii. A name with its town missing.
        //
        // "Mahkamah Majistret di sini" - the Magistrate's Court HERE - points
        // at the dateline, which step 3a has already struck. Told this in the
        // playbook the model resolved it correctly in its reason and wrote the
        // unresolved phrase as the name anyway, one run in three. Resolved
        // first so every guard below sees a real name.
        // 3b-i. A name nobody here can read cannot be shown or searched.
        //
        // Tamil and Chinese sources write the place in their own script unless
        // the model remembers not to, and it does not always remember. The
        // failure is not cosmetic: "伯明翰机场, 伯明翰, 英国" - Birmingham
        // Airport, in Britain - resolved to INTI International University in
        // Nilai, and nothing downstream could tell, because the name does not
        // say Britain in any alphabet the plausibility check reads. Tamil names
        // simply miss and land in the human queue.
        if (!LatinName::isUsable($place)) {
            // "西里京 (Serikin)": the Latin name is right there in brackets
            $latin = LatinName::latinForm($place);
            $corrections[] = ($latin !== null ? 'place_latin_from_brackets:' : 'place_not_latin:') . mb_substr((string) $place, 0, 40);
            $place = $latin;
        }

        $place = DeicticPlace::resolve($place, $parsed['places_named'] ?? [], $corrections);

        $place = $this->placeMustBeHappened($place, $parsed['places_named'] ?? [], $corrections);

        // 3c. A state is not a place a reader can be near.
        //
        // Sarawak is 124,000 square kilometres and its centroid is jungle.
        // Pinning a story there tells a reader in Kuching nothing, and a story
        // that genuinely concerns a whole state concerns everybody in it -
        // which is what national means here.
        // 3d. A table of places is a bulletin, not a local story.
        //
        // A haze report named twenty-four air quality stations from Kuching to
        // Seremban and was answered with Kuching, because Kuching was typed
        // first. The article says the same kind of thing about every place in
        // it. Told this in prose the model still answered Kuching, so it is
        // counted here instead.
        $happenedCount = 0;

        foreach ((array) ($parsed['places_named'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['role'] ?? '') === 'happened') {
                $happenedCount++;
            }
        }

        if ($happenedCount > self::BULLETIN_PLACES && !empty($place)) {
            $corrections[] = 'bulletin:' . $happenedCount . '_places';
            $place = null;
        }

        // Test the FIRST component as well as the whole string. "Sarawak" is
        // caught; "Sarawak, Malaysia" was not, because the guard only ever
        // looked at the name entire - so a state with its country appended
        // walked straight through and a haze story was pinned at the centre of
        // Sarawak, three hundred kilometres from anybody.
        //
        // Only the FIRST part: "Kuching, Sarawak" is a city with its state
        // attached and must stay, which is the whole reason the state is
        // written after a smaller place everywhere else here.
        $head = trim(explode(',', (string) $place)[0]);

        // ⛔⛔ ONLY THE FIRST COMPONENT. Testing the WHOLE string threw away the
        // pin of every story that happened abroad.
        //
        // The playbook requires the country abroad - "THE COUNTRY IS NOT
        // OPTIONAL ABROAD... Doha, Qatar. Kathmandu, Nepal" - and this guard
        // then read the country in the string and called the place too big to
        // be near. Measured 4 Sep 2026:
        //
        //   Bukit Batok, Singapore   dropped        Bukit Batok        kept
        //   Kathmandu, Nepal         dropped        Kuching, Sarawak   kept
        //   Bangkok, Thailand        dropped        Kuantan, Pahang    kept
        //
        // So the prompt demanded the country and the validator punished it, and
        // every foreign story silently became national. The owner found the
        // Singapore half of it: "its crime located at Bukit botak".
        //
        // The head test loses nothing. "Sarawak, Malaysia" - the case the whole
        // string test was added for - is caught by its head, "Sarawak", which
        // is what the comment above already says should decide.
        if (PlaceScale::isTooBigToBeNear($head)) {
            // A correction, not a failure: the place is now null and the story
            // is a national one, which is a correct outcome. Recording this in
            // $errors refused the whole story for having been put right.
            $corrections[] = 'place_too_big:' . $place;
            $place = null;
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

        // Our own headline: 10-200 characters, one line, or nothing (the publisher's stays).
        $ownTitle = trim(preg_replace('/\s+/u', ' ', (string) ($parsed['title'] ?? '')), " \t\n\r\"'“”");
        $ownTitle = mb_strlen($ownTitle) >= 10 ? mb_substr($ownTitle, 0, 200) : null;

        return [
            'valid'         => empty($errors),
            'corrections'   => $corrections,
            'title'         => $ownTitle,
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
            'my'            => array_key_exists('my', $parsed) ? (int) $parsed['my'] : 1,
            'why'           => $parsed['why'] ?? null,
            'a'             => (int) ($parsed['a'] ?? 0),
            'place'         => $place,
            'relevance_mode'=> $relevanceMode,
            // Kept so a coarse answer can be checked against what the model
            // said the text contained, rather than argued about.
            'places_named'  => $this->placesNamed($parsed['places_named'] ?? []),
            'new_sub'       => $parsed['new_sub'] ?? null,
            'is_article'    => $isArticle,
            'lat'           => $lat,
            'lng'           => $lng,
            'errors'        => $errors,
        ];
    }

    /**
     * Keep each named place with the role the model gave it.
     *
     * The role is the reasoning, and it is the part worth keeping: it says why
     * a story about a detention in Putrajaya over a lease in Saudi Arabia is
     * neither a Putrajaya story nor a Saudi one. Without it all that survives
     * is a list of names and an answer, and a wrong answer cannot be argued
     * with - only overruled.
     *
     * A plain string is still accepted. A reply that degrades should lose the
     * analysis, not the story.
     */
    private function placesNamed(mixed $raw): array
    {
        $out = [];

        foreach ((array) $raw as $entry) {
            if (is_string($entry)) {
                $place = trim($entry);
                $role = null;
                $why = null;
            } elseif (is_array($entry)) {
                $place = trim((string) ($entry['p'] ?? $entry['place'] ?? ''));
                $role  = trim((string) ($entry['role'] ?? '')) ?: null;
                $why   = trim((string) ($entry['why'] ?? '')) ?: null;
            } else {
                continue;
            }

            if ($place === '') {
                continue;
            }

            $out[] = array_filter([
                'p'    => mb_substr($place, 0, 120),
                'role' => $role ? mb_substr($role, 0, 20) : null,
                'why'  => $why ? mb_substr($why, 0, 200) : null,
            ], fn ($v) => $v !== null);

            if (count($out) >= 25) {
                break;
            }
        }

        return $out;
    }

    /**
     * When AI completely fails, apply rule-based fallback.
     * Preserves whatever was extracted; does NOT pollute with bad AI data.
     */
    private function applyFallback(AiProcessingJob $job, $extraction, NewsItem $item): bool
    {
        // ⛔ ai_category first: primary_category went stale for a long time
        // (see the write above), so a fallback that read it alone filed every
        // story as 'others' on exactly the days the model was unavailable -
        // the days the fallback is the only thing running.
        $extractedCategory = $item->ai_category ?: ($item->primary_category ?? null);
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
    /**
     * What a story is about, even when we are not going to run it.
     *
     * Returns null when the model gave nothing to score, which is not an error
     * here - it only means the discard carries no category, exactly as before.
     */
    /**
     * Does the place the model named sit in a country the site serves?
     *
     * Deliberately narrower than servesThisStory(): the masthead is NOT
     * consulted. This runs on a story the model wanted to throw away, and a
     * Singapore paper publishes a great deal of foreign news - rescuing by
     * masthead would have kept Seoul's otters and a US aid package. Only the
     * story's own answer about where it happened counts here.
     */
    private function placeIsInAServedCountry(array $validation): bool
    {
        $place = trim((string) ($validation['place'] ?? ''));

        if ($place === '' || mb_strtolower($place) === 'null') {
            return false;
        }

        $named = \App\Services\Geo\CountryCode::forPlace($place, null);

        return $named !== null
            && in_array(mb_strtoupper($named), \App\Services\Geo\SourceCountry::siteCountries(), true);
    }

    /**
     * Does this story belong to a country the site actually serves?
     *
     * Only asked when the model has said there is no Malaysian angle. The
     * question then is not "is this Malaysian" but "is this ours at all".
     *
     * The place is trusted first because it is the story's own answer: the
     * playbook requires the country to be named abroad, so "Dalin Township,
     * Chiayi County, Taiwan" identifies itself as Taiwanese and stays
     * discarded, while "Bukit Batok, Singapore" identifies itself as ours.
     *
     * Only when the place names no country at all does the masthead decide -
     * a Singapore paper's story with no country in its place text is a
     * Singapore story. That is a fallback, not the rule: a masthead publishes
     * foreign news too, which is exactly why the place is asked first.
     */
    private function servesThisStory(array $validation, object $item): bool
    {
        $serves = \App\Services\Geo\SourceCountry::siteCountries();   // ['MY', 'SG']

        $place = trim((string) ($validation['place'] ?? ''));

        if ($place !== '' && mb_strtolower($place) !== 'null') {
            $named = \App\Services\Geo\CountryCode::forPlace($place, null);

            if ($named !== null) {
                return in_array(mb_strtoupper($named), $serves, true);
            }
        }

        $masthead = \App\Services\Geo\SourceCountry::iso2($item->source ?? null);

        return $masthead !== null && in_array(mb_strtoupper($masthead), $serves, true);
    }

    private function categoryOf(array $validation): ?array
    {
        if (empty($validation['rel'])) {
            return null;
        }

        try {
            $outcome = (new CategoryScorer())->score(
                $validation['rel'],
                $validation['sub'] ?? [],
                ['gps' => false, 'cap' => 1.0, 'slots' => []]
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($outcome['primary']['name'])) {
            return null;
        }

        return [
            'ai_category'  => mb_strtolower($outcome['primary']['name']),
            'sub_category' => $outcome['sub']['name'] ?? null,
        ];
    }

    private function refuse(
        NewsItem $item,
        AiProcessingJob $job,
        ?string $errorCode,
        string $why,
        ?array $categories = null
    ): void {
        $job->update([
            'ai_status'        => 'insufficient_content',
            'validation_notes' => 'policy: ' . $why . ($errorCode ? " ({$errorCode})" : ''),
            'processed_at'     => now(),
        ]);

        $item->update(array_filter([
            'discarded'       => true,
            'error_code'      => $errorCode,
            'meta_confidence' => 0.0,
            'ai_status'       => 'discarded',
            'ai_processed_at' => now(),
            'status'          => 'rejected',
            'spec_version'    => self::PROMPT_VERSION,
            'ai_category'     => $categories['ai_category'] ?? null,
            'sub_category'    => $categories['sub_category'] ?? null,
        ], fn ($v) => $v !== null));

        $this->warn("  DISCARD {$item->id}: {$why}" . ($errorCode ? " [{$errorCode}]" : ''));

        Log::info('Policy discard', [
            'news_item_id' => $item->id,
            'error_code'   => $errorCode,
            'why'          => $why,
        ]);
    }

    private function buildPrompt(string $title, string $text, string $source, array $slots = []): string
    {
        // Assembled from its parts rather than written here, so the newsroom
        // can read and change the reasoning without a deployment - and so the
        // prompt viewer in the panel shows the same text the model is sent
        // rather than a reconstruction that drifts away from it.
        //
        // The output contract stays in the assembler's own code: the parser
        // that reads the reply is written against those exact field names, and
        // an editor tidying them would break classification silently.
        return (new PromptAssembler())->build($title, $text, $source, $slots);
    }

    private function callDeepSeek(string $apiKey, string $prompt): array
    {
        // The task's provider answers; the helper steps in when it cannot (the AI panel decides).
        // the publisher's country picks the notes, as it already picks the playbook and the rules
        $client = \App\Services\Ai\AiRouter::for('enrich', $this->backlog ?? 0, $this->enrichCountry);
        // a weak answer (no JSON, no category relevance, no summary) goes to the helper before it is accepted
        $accept = function (string $text) {
            try {
                $parsed = $this->parseAiResponse($text);
            } catch (\Throwable $e) {
                return 'not JSON';
            }
            if ((int) ($parsed['d'] ?? 0) === 1 || (int) ($parsed['a'] ?? 1) === 0) {
                return true;   // a discard or a non-article is a complete answer, not a weak one
            }
            if (empty($parsed['rel'])) {
                return 'no category relevance';
            }
            if (mb_strlen(trim((string) ($parsed['summary'] ?? ''))) < 40) {
                return 'summary missing';
            }
            return true;
        };
        $response = $client->post(30, [
                'model'    => self::MODEL,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.3,
            ], false, $accept);

        if (!$response->successful()) {
            throw new \Exception('DeepSeek API error: ' . $response->status() . ' - ' . $response->body());
        }

        $body = $response->json();
        if (!isset($body['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid DeepSeek response: missing content field');
        }
        $body['raw_content'] = $body['choices'][0]['message']['content'];
        $body['provider'] = $client->providerName();
        $body['model'] = $client->model();
        return $body;
    }

    /**
     * Fetch the article again, and only return it if it IS the article.
     *
     * Two checks, both drawn from what re-fetching actually does here rather
     * than from what it ought to do. Measured on 24 day-old stories: two
     * publishers answered 403 while their feeds kept working, and three
     * returned a stub of exactly 814 characters under HTTP 200 - New Straits
     * Times twice and Harian Metro once, against originals of 3,280, 1,846 and
     * 1,819. A stub arrives looking like a success.
     *
     * So: it must come back at a reasonable fraction of the size we recorded,
     * and it must still open with the story we recorded. Either check alone
     * would pass something it should not - a different article of the right
     * length, or the right opening followed by a paywall.
     *
     * Returns null to mean "do not judge this story", which the caller honours
     * by leaving the existing answer untouched.
     */
    private function refetchBody(NewsItem $item, $extraction): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)',
            ])->timeout(30)->get((string) $item->url);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'refetch');
        file_put_contents($tmp, $response->body());

        $command = 'python3 -c ' . escapeshellarg(
            'import sys,trafilatura;'
            . 'h=open(sys.argv[1],encoding="utf-8",errors="replace").read();'
            . 'print(trafilatura.extract(h, include_tables=True, include_comments=False) or "")'
        ) . ' ' . escapeshellarg($tmp);

        $text = trim((string) shell_exec($command . ' 2>/dev/null'));
        @unlink($tmp);

        if ($text === '') {
            return null;
        }

        // Length: a quarter of the original is a stub, not an update.
        $was = (int) $extraction->original_length;

        if ($was > 0 && mb_strlen($text) < $was * 0.6) {
            Log::info('Re-fetch refused on length', [
                'news_item_id' => $item->id, 'was' => $was, 'now' => mb_strlen($text),
            ]);

            return null;
        }

        // Identity: still the same story, allowing for a rewritten intro.
        if (!empty($extraction->body_opening)) {
            similar_text(
                (string) $extraction->body_opening,
                mb_substr($text, 0, 280),
                $percent
            );

            if ($percent < 55) {
                Log::info('Re-fetch refused on content', [
                    'news_item_id' => $item->id, 'similarity' => round($percent),
                ]);

                return null;
            }
        }

        return $text;
    }

    /**
     * Keep the answer inside the working.
     *
     * If any place was marked happened, the answer has to be one of them. If
     * none was, there is no place and the answer is null - a story with no
     * event anywhere is national news, which is a correct and common outcome.
     *
     * The comparison is lenient one way round: the model is asked to write the
     * answer narrow-first with its wider place appended, so "Lebuh China,
     * George Town, Pulau Pinang" is the entry "Lebuh China" answered properly
     * and must not be rejected for it.
     */
    private function placeMustBeHappened(?string $place, mixed $roles, array &$corrections): ?string
    {
        $happened = [];

        foreach ((array) $roles as $entry) {
            if (is_array($entry) && ($entry['role'] ?? '') === 'happened') {
                $name = trim((string) ($entry['p'] ?? ''));

                if ($name !== '') {
                    $happened[] = mb_strtolower($name);
                }
            }
        }

        // No roles at all: an older or degraded reply. Leave the answer alone
        // rather than throwing away a location over a missing field.
        if (!is_array($roles) || $roles === []) {
            return $place;
        }

        if ($happened === []) {
            if (!empty($place)) {
                $corrections[] = 'place_not_happened:' . mb_substr($place, 0, 40);
            }

            return null;
        }

        if (empty($place)) {
            return null;
        }

        $answer = mb_strtolower($place);

        // Take the fullest form of its own answer.
        //
        // A remand story listed "Mahkamah Majistret, Putrajaya" among the places
        // it marked happened, and then answered "Mahkamah Majistret". The guard
        // saw a match and kept the short one - so a name naming dozens of courts
        // went to the geocoder, failed, and landed in the review queue. The
        // model had already written the better name one field away.
        $fullest = null;

        foreach ($happened as $name) {
            if ($name === $answer) {
                return $place;
            }

            // The answer is part of a place it named: that place is the answer,
            // said properly.
            if (str_contains($name, $answer)
                && ($fullest === null || mb_strlen($name) > mb_strlen($fullest))) {
                $fullest = $name;
            }
        }

        if ($fullest !== null) {
            foreach ((array) $roles as $entry) {
                if (!is_array($entry) || ($entry['role'] ?? '') !== 'happened') {
                    continue;
                }

                $original = trim((string) ($entry['p'] ?? ''));

                if (mb_strtolower($original) === $fullest) {
                    $corrections[] = 'place_completed:' . mb_substr($place, 0, 40);

                    return mb_substr($original, 0, self::MAX_PLACE_LEN);
                }
            }
        }

        // The answer already contains one of them, so it is the fuller form.
        foreach ($happened as $name) {
            if (str_contains($answer, $name)) {
                return $place;
            }
        }

        // It named places where things happened and then answered with a
        // different one. Fall back to the narrowest it did mark - the first,
        // since the ladder names the narrowest first.
        $corrections[] = 'place_not_happened:' . mb_substr($place, 0, 40);

        foreach ((array) $roles as $entry) {
            if (is_array($entry) && ($entry['role'] ?? '') === 'happened' && !empty($entry['p'])) {
                return mb_substr(trim((string) $entry['p']), 0, self::MAX_PLACE_LEN);
            }
        }

        return null;
    }

    /**
     * The sub-category, allowing for one the taxonomy does not have yet.
     *
     * A proposal is only looked at when the scorer found nothing that fits -
     * which is precisely the case the owner asked about, a baseball story
     * against eighteen sports that are not baseball. When something does fit,
     * the proposal is ignored and the existing shelf is used, because a
     * taxonomy that grows whenever the model feels creative is no taxonomy.
     */
    private function subCategoryFor(array $outcome, array $validation): ?string
    {
        $scored = $outcome['sub']['name'] ?? null;

        // Something fits. Nothing to decide.
        if ($scored !== null && !in_array($scored, ['Others', 'General'], true)) {
            return $scored;
        }

        $created = (new \App\Services\Classification\SubCategoryProposals())->consider(
            $validation['new_sub'] ?? null,
            (string) ($outcome['primary']['name'] ?? '')
        );

        if ($created) {
            $this->line(sprintf('  sub-category: %s / %s', $created->primary_category, $created->sub_category));

            return $created->sub_category;
        }

        return $scored;
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
            'title'     => $parsed['title']     ?? null,   // our own headline (4 Sep 2026)
            'category'  => $parsed['category']  ?? null,
            // Whitelist parser: a key omitted here never reaches validation.
            'sub_category' => $parsed['sub_category'] ?? null,
            'lang'         => $parsed['lang'] ?? null,
            // Whitelist parser: a key omitted here never reaches validation.
            'd'            => (int) ($parsed['d'] ?? 0),
            'e'            => $parsed['e'] ?? null,
            'g'            => (int) ($parsed['g'] ?? 0),
            'a'            => (int) ($parsed['a'] ?? 0),
            'my'           => array_key_exists('my', $parsed) ? (int) $parsed['my'] : 1,
            'why'          => $parsed['why'] ?? null,
            'rel'          => is_array($parsed['rel'] ?? null) ? $parsed['rel'] : [],
            'sub'          => is_array($parsed['sub'] ?? null) ? $parsed['sub'] : [],
            // Omitting a key here is how it never reaches validation. That is
            // not a hypothetical: places_named was dropped exactly this way for
            // the entire life of the field.
            'new_sub'      => is_array($parsed['new_sub'] ?? null) ? $parsed['new_sub'] : null,
            't'            => is_array($parsed['t'] ?? null) ? $parsed['t'] : null,
            'place'     => $parsed['place']     ?? null,
            // The workings behind 'place': each place the article names, the
            // role the model gave it, and the words that put it there. Omitted
            // here for as long as the field has existed, which is why it has
            // always arrived empty.
            'places_named' => is_array($parsed['places_named'] ?? null) ? $parsed['places_named'] : [],
            'relevance' => $parsed['relevance']  ?? 'category_only',
            'is_article'=> isset($parsed['is_article']) ? (bool) $parsed['is_article'] : true,
            'lat'       => isset($parsed['lat']) && is_numeric($parsed['lat']) ? (float) $parsed['lat'] : null,
            'lng'       => isset($parsed['lng']) && is_numeric($parsed['lng']) ? (float) $parsed['lng'] : null,
        ];
    }

    /**
     * What this call cost, counting cached input at the cached rate.
     *
     * Charging every input token at the fresh rate overstated the bill by
     * roughly three times once the prompt was reordered and most of it started
     * arriving from cache.
     */
    private function estimateCost(array $response): string
    {
        $usage = $response['usage'] ?? [];

        // another provider answered (the AI panel's helper): its own prices, from the panel
        if (!empty($response['provider']) && $response['provider'] !== 'deepseek') {
            $p = \App\Services\Ai\AiRouter::providers()[$response['provider']] ?? null;

            if ($p) {
                return (string) round(((int) ($usage['prompt_tokens'] ?? 0)) / 1e6 * (float) $p['price_in'] + ((int) ($usage['completion_tokens'] ?? 0)) / 1e6 * (float) $p['price_out'], 6);
            }
        }

        if (isset($usage['prompt_cache_hit_tokens']) || isset($usage['prompt_cache_miss_tokens'])) {
            return (string) (new AiSpend())->costOf(
                (int) ($usage['prompt_cache_hit_tokens'] ?? 0),
                (int) ($usage['prompt_cache_miss_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                self::MODEL
            );
        }

        // A provider that reports no cache split: everything is fresh input.
        $in  = $response['usage']['prompt_tokens'] ?? 0;
        $out = $response['usage']['completion_tokens'] ?? 0;
        $cost = ($in * 0.15 / 1_000_000) + ($out * 0.60 / 1_000_000);
        return number_format($cost, 6, '.', '');
    }
}

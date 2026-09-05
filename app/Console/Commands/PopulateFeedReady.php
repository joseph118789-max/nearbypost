<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\FeedReadyItem;
use App\Models\AiProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\DuplicateGuard;

/**
 * Precompute feed_ready rows from fully-processed news_items.
 * Idempotent: upserts per news_item_id, only creates from items
 * that have passed through alias enrichment and precision interpretation.
 *
 * PROMOTION GATE — this command is the authoritative path from
 * canonical pipeline state into public serving state.
 * "When in doubt, skip."
 */
class PopulateFeedReady extends Command
{
    protected $signature = 'ingest:populate-feed
        {--news_item_id= : Populate a specific item only}
        {--limit=100    : Max items per run}
        {--all          : Ignore the up-to-date check and re-sync everything}';

    protected $description = 'Upsert feed_ready_items from processed news_items (idempotent)';

    // ── Canonical relevance modes (D11 contract) ───────────────────────────
    private const ALLOWED_RELEVANCE_MODES = ['category_only', 'location_only', 'location_and_category'];

    // ── Valid geo precision types for geo-bearing items ─────────────────
    private const VALID_PRECISION_TYPES = ['exact_area', 'approximate_area', 'region', 'broad'];

    // ── Allowed AI job statuses for promotion ───────────────────────────
    private const ALLOWED_AI_STATUSES = ['success', 'fallback_used'];

    public function handle(): int
    {
        // ⛔ MARK THE PUBLISHERS BEFORE SERVING THEM. A story from a site that
        // ASKED to be carried is a different claim on a reader's trust than one
        // we found ourselves, and the card cannot tell them apart unless origin
        // says so. One indexed statement, idempotent, before anything is built:
        // doing it per story would be a lookup on every row forever.
        DB::table('news_items')
            ->whereIn('source', DB::table('sources')
                ->where('source_type', 'contributed')->select('name'))
            ->where('origin', 'scraper')
            ->update(['origin' => 'publisher', 'updated_at' => now()]);

        // ⛔ AND THE GATE CLOSES ON ARRIVAL, not when a model gets round to it.
        // Marking here as well as in publisher:vet means an item is already
        // being withheld the moment it exists; if it were marked only alongside
        // the model call, it would sit servable for as long as the queue was
        // behind - exactly when you would least want it to.
        \App\Services\Ingest\PublisherVetting::markArrivals();

        // ⛔⛔ AND ANYTHING NO LONGER PERMITTED COMES DOWN, whoever changed it.
        //
        // PublisherVetting takes an item off the site when IT sets a status -
        // but a status can also change from an admin screen, a repair script,
        // or a hand-written UPDATE, and then a rejected item would sit on the
        // site indefinitely because this command only ever creates and updates
        // rows. Tested exactly that way and it did.
        //
        // So the rule is enforced here too, once per run, against whatever the
        // column currently says. Two places assert it and neither relies on the
        // other having been called.
        $pulled = DB::table('feed_ready_items')
            ->whereIn('news_item_id', DB::table('news_items')
                ->whereIn('publisher_review_status', ['pending', 'held', 'rejected'])
                ->select('id'))
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        if ($pulled > 0) {
            $this->warn("  took {$pulled} publisher item(s) off the site - not passed for showing");
        }

        $newsItemId = $this->option('news_item_id');
        $limit     = (int) $this->option('limit');

        $query = NewsItem::query()
            ->where('status', 'active')
            // A story the deduplicator has folded into another must not be put
            // back. Without this the two stages fight every five minutes: dedup
            // deactivates the serving row, populate-feed recreates it, and the
            // reader sees the same collision reported nine times.
            ->whereNull('duplicate_of')

            // ⛔⛔ NOTHING FROM A CONNECTED PUBLISHER IS SERVED UNTIL IT HAS
            // BEEN READ. Approving their site approved what they were
            // publishing that week, not everything they will publish
            // afterwards - a site can change hands, or simply start posting
            // advertisements.
            //
            // The test is for 'not_required' or 'passed', NOT "anything except
            // rejected". Written the other way, an item that is pending, or
            // held for a person, or carries a status added later, would be
            // served - and the whole check would be decorative the first time
            // the vetting queue fell behind. Ordinary scraped stories are
            // 'not_required' and are untouched by this.
            ->whereIn('publisher_review_status', ['not_required', 'passed'])
            // A multi-point story is served one row per place by
            // ingest:multipoint, which owns those rows. Rebuilding it here
            // collapses five towns into one and relabels it from a stale
            // canonical_place_name - which is how a Sarawak haze story ended
            // up served at Kuching's coordinates under the label "Sarawak".
            ->where('is_multi_point', false)
            ->whereNotNull('title')
            ->whereNotNull('published_at');

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        } elseif (!$this->option('all')) {
            // Without this predicate the command re-processed the same first
            // 100 ids on every run and never reached the rest of the table.
            $query->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('feed_ready_items')
                  ->whereColumn('feed_ready_items.news_item_id', 'news_items.id')
                  ->whereColumn('feed_ready_items.updated_at', '>=', 'news_items.updated_at');
            });
        }

        // Newest first. Oldest-first spent every run on the same few thousand
        // stories that can never promote - their enrichment failed long ago and
        // fails again each time - so nothing published today was ever reached.
        // If a run cannot clear the backlog, recent news is what it should
        // clear.
        $items = $query->limit($limit)->orderByDesc('published_at')->orderByDesc('id')->get();
        $this->info("Populating feed_ready: {$items->count()} items.");

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $result = $this->upsert($item);
            if ($result === 'created') $created++;
            elseif ($result === 'updated') $updated++;
            else $skipped++;
        }

        // A story's time can improve after it was served (the page re-read,
        // the feed corrected). The served row follows the story, every run.
        $synced = DB::update(<<<'SQL'
            update feed_ready_items f
               set published_at = n.published_at, published_precision = n.published_precision, sort_timestamp = n.published_at,
                   event_start = n.event_start, event_end = n.event_end, updated_at = now()
              from news_items n
             where n.id = f.news_item_id
               and (f.published_precision is distinct from n.published_precision
                    or f.published_at is distinct from n.published_at
                    or f.event_start is distinct from n.event_start or f.event_end is distinct from n.event_end)
            SQL);

        // A pin can move after the row was served: a person answered, the
        // sweep found the exact spot, a stand-in was replaced. The primary
        // row follows the story's pin; the other rows of a multi-point story
        // carry their own places and are left alone.
        $moved = DB::update(<<<'SQL'
            update feed_ready_items f
               set lat = n.latitude, lng = n.longitude, precision_type = coalesce(n.precision_type, f.precision_type),
                   geo_country_code = coalesce(n.geo_country_code, f.geo_country_code), geo_state_code = coalesce(n.geo_state_code, f.geo_state_code),
                   canonical_place_name = n.canonical_place_name, location_label = coalesce(n.canonical_place_name, n.main_place_text, f.location_label),
                   updated_at = now()
              from news_items n
             where n.id = f.news_item_id and f.is_primary_location = true
               and n.geocode_status = 'success' and n.latitude is not null and n.longitude is not null
               and (f.lat is distinct from n.latitude or f.lng is distinct from n.longitude)
            SQL);

        $this->info("Done. created={$created} updated={$updated} skipped={$skipped} time-synced={$synced} pin-synced={$moved}");
        return 0;
    }


    /**
     * Choose the best category for a feed_ready item.
     * Prefers AI-enriched category, falls back intelligently.
     */
    private function bestCategory(NewsItem $item, $aiJob): string
    {
        $aiCategory = $item->ai_category;
        $validatedCat = $aiJob?->validated_category;

        // If NewsItem has a proper AI category (not 'others'/'other'), use it
        if ($aiCategory && !in_array(strtolower($aiCategory), ['others', 'other', ''], true)) {
            return $aiCategory;
        }

        // If AI job has a proper validated_category, use it
        if ($validatedCat && !in_array(strtolower($validatedCat), ['others', 'other', ''], true)) {
            return $validatedCat;
        }

        // Fall back to RSS primary_category only if it's a real category (not 'others'/'news')
        $rssCat = $item->primary_category;
        if ($rssCat && !in_array(strtolower($rssCat), ['others', 'other', 'news', ''], true)) {
            return $rssCat;
        }

        // Last resort
        return $aiCategory ?: $validatedCat ?: $rssCat ?: 'other';
    }

    /**
     * Upsert a single feed_ready_item.
     * Returns 'created', 'updated', or 'skipped'.
     *
     * Promotion gate logic:
     * 1. AI job must exist and have allowed status (success / fallback_used)
     * 2. relevance_mode must be in the canonical set
     * 3. category_only items: promote without geo requirements
     * 4. location_only / location_and_category items: require location label
     *    AND valid precision type — prevents half-baked geo rows reaching feed
     * 5. category_only items: allowed without geo
     * 6. location-bearing items: require location label + valid precision
     */
    private function upsert(NewsItem $item): string
    {
        // ── Gate 0: an item refused by policy is never served ───────────
        if ($item->discarded) {
            return 'skipped';
        }

        // A story with no Malaysian angle is not for this audience, wherever
        // it happened.
        if ($item->malaysia_relevant === false) {
            return 'skipped';
        }

        // ── Gate 1: AI enrichment must exist and be in allowed state ────
        $aiJob = $item->aiProcessingJob;

        // The gate asks whether this story has been classified. For almost
        // everything the answer lives in ai_processing_jobs, because almost
        // everything is classified by asking the model.
        //
        // Events are not. AllEvents and MyCEB publish name, venue, town and
        // dates as structured data, so the extractor records the classification
        // straight onto the story and never spends a request re-deriving a fact
        // it was handed. That left the answer in the right place but the wrong
        // table: every event row failed this gate, and forty-odd conferences
        // sat on the site with their coordinates stranded on news_items - live,
        // but impossible to find by distance, which is the one thing this site
        // is for.
        //
        // So: a job if there is one, the story's own record if there is not.
        // The allowed-status list is the same either way, and Gate 0 has
        // already refused anything discarded, so nothing unclassified passes.
        $status = $aiJob->ai_status ?? $item->ai_status;

        if (!in_array($status, self::ALLOWED_AI_STATUSES, true)) {
            // skipped_no_ai_job
            return 'skipped';
        }

        // ── Gate 2: relevance_mode must be in canonical set ────────────
        $mode = $aiJob?->relevance_mode ?: $item->relevance_mode;
        if (!in_array($mode, self::ALLOWED_RELEVANCE_MODES, true)) {
            // skipped_invalid_mode — coerce nothing, just skip
            return 'skipped';
        }

        // ── Gate 3: location_label source must exist ───────────────────
        $locationLabel = $item->canonical_place_name ?: $item->main_place_text;

        // ── Gate 4: geo-bearing items require valid precision ───────────
        // category_only: promoted without geo requirements
        // location_only / location_and_category: require location label AND valid precision
        if ($mode !== 'category_only') {
            $precision = $item->precision_type ?? null;
            $hasValidPrecision = in_array($precision, self::VALID_PRECISION_TYPES, true);

            if (empty($locationLabel) || !$hasValidPrecision) {
                // skipped_incomplete_geo — half-baked location row, do not serve
                return 'skipped';
            }
        }

        // ── All gates passed: build serving row ─────────────────────────
        $precision = $item->precision_type ?? null;
        if ($precision && !in_array($precision, self::VALID_PRECISION_TYPES, true)) {
            $precision = null;
        }

        $row = [
            'title'              => $item->ai_title ?: $item->title,
            // ⛔ A summary is only served when there was something to summarise.
            //
            // A story whose stored text was 111 characters - cut off mid-word,
            // before any figures - was shown to readers as "the new prices are
            // RM3.35, RM3.18 and RM3.08". The model filled the gap and the site
            // published the result under the publisher's name. Where too little
            // was read, the genuine excerpt goes out instead: short, stopping
            // mid-sentence, and true.
            'summary'            => $this->honestSummary($item, $aiJob),
            'source'             => $item->source,
            'url'               => $item->url,
            'published_at'       => $item->published_at,
            'published_precision' => $item->published_precision ?? null,
            // A promotion runs between two dates rather than happening on one.
            // Carried across so the feed can keep it while it is still on -
            // left behind, a sale would be buried the morning after it appeared.
            'event_start'        => $item->event_start ?? null,
            'event_end'          => $item->event_end ?? null,
            'event_open_ended'   => (bool) ($item->event_open_ended ?? false),
            'primary_category'   => $this->bestCategory($item, $aiJob),
            'secondary_category' => $item->secondary_category,
            'sub_category'       => $item->sub_category,
            'location_label'     => $locationLabel ?: null,
            'lat'                => $item->latitude,
            'lng'                => $item->longitude,
            'precision_type'    => $precision,
            'distance_km'        => null,
            'relevance_mode'     => $mode,
            'is_article'         => $aiJob?->is_article ?? $item->is_article ?? true,
            'is_active'          => true,
            // Extended serving fields
            'canonical_place_name' => $item->canonical_place_name,
            'geo_confidence_score'=> $item->geo_confidence_score,
            'coverage_type'       => $item->coverage_type,
            'geo_country_code'    => $item->geo_country_code ?? null,
            'geo_state_code'      => $item->geo_state_code ?? null,
            'geo_city_code'       => $item->geo_city_code ?? null,
            'sort_timestamp'      => $item->published_at,
            'origin'              => $item->origin ?: 'scraper',
            'image_path'          => $item->image_path,
            'updated_at'          => now(),
        ];

        $existing = FeedReadyItem::where('news_item_id', $item->id)->first();

        // Last check before serving: does the reader already have this story
        // from another source? Batch de-duplication cannot see across runs.
        if (!$existing) {
            $guard = new DuplicateGuard();
            $twin  = $guard->findPublished($item->title, (string) $item->published_at, $item->id);

            if ($twin) {
                if (!$guard->preferIncoming((object) $row, $twin)) {
                    return 'skipped';
                }

                // The incoming version is better: take over the served slot so
                // the reader's link points at the article, not a redirect.
                FeedReadyItem::where('id', $twin->id)->update(['is_active' => false]);
                Log::info('Duplicate superseded', [
                    'kept'      => $item->id,
                    'superseded'=> $twin->news_item_id,
                    'title'     => mb_substr($item->title, 0, 80),
                ]);
            }
        }

        if ($existing) {
            $existing->update($row);
            Log::debug('FeedReady updated', ['news_item_id' => $item->id]);
            return 'updated';
        }

        $row['news_item_id'] = $item->id;
        $row['created_at']   = now();
        FeedReadyItem::create($row);
        Log::debug('FeedReady created', ['news_item_id' => $item->id]);
        return 'created';
    }

    /**
     * Below this there was not enough text to summarise from.
     *
     * Six hundred characters is about three sentences - enough to say what
     * happened. Under that, a two-sentence summary is the model filling gaps.
     */
    private const MIN_TO_SUMMARISE = 600;

    /**
     * The model's summary where it had an article to read, the article's own
     * opening where it did not.
     */
    private function honestSummary($item, $aiJob): ?string
    {
        $held = DB::table('extraction_jobs')
            ->where('news_item_id', $item->id)
            ->whereIn('extraction_status', ['success', 'fallback_used'])
            ->orderByDesc('id')
            ->value('extracted_text');

        if ($held !== null && mb_strlen($held) < self::MIN_TO_SUMMARISE) {
            $excerpt = trim(preg_replace('/\s+/u', ' ', $held));

            return $excerpt !== '' ? mb_substr($excerpt, 0, 400) : null;
        }

        // The story's own AI summary first. It is the same text, kept on the
        // row that is actually about the story, and it survives the pruning of
        // the job record - so a story re-promoted a month later still reads
        // properly instead of falling back to its raw RSS description.
        return $item->ai_summary ?: ($aiJob?->validated_summary ?: $item->summary);
    }
}

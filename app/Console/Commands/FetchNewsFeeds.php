<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\Classification\ContentPolicy;
use App\Services\IndexCrawler;

/**
 * Fetch every registered feed, reconcile what comes back, then submit it.
 *
 * The order matters. Sources are read first, all of them, and only then is the
 * combined result de-duplicated and posted. Doing it the other way round - post
 * as you go - means the same story enters the database several times, once per
 * feed that carried it, and no later stage can tell which copy is canonical.
 * That matters more with every source added: an aggregator and a publisher's own
 * feed carry the same article under different URLs by design.
 *
 * What is learnt about reading a source is written back to its fetch_recipe, so
 * a quirk discovered once is applied from then on rather than rediscovered.
 * Three that cost real debugging time:
 *
 *   - simplexml_load_file() follows no redirects and sends no user agent. Free
 *     Malaysia Today answered 301 and Bernama 404, so for months every article
 *     on the site came from the one remaining source.
 *   - Harian Metro and Berita Harian stamp Malaysian local time but label it
 *     +0000, putting every story eight hours in the future.
 *   - Categories must not be guessed at ingestion. The old script stamped
 *     'nation' on everything, which is why that value covers thousands of rows.
 */
class FetchNewsFeeds extends Command
{
    protected $signature = 'ingest:fetch
        {--source= : Fetch a single source by id or name}
        {--tier=primary : Which priority tier to fetch (primary, secondary, all)}
        {--limit=60 : Max items to take from any one feed}
        {--dry-run : Fetch and report, but do not send anything}';

    protected $description = 'Fetch registered feeds, de-duplicate across sources, and submit for ingestion';

    private const INGEST_URL  = 'http://127.0.0.1:8080/api/internal/ingest/batch';
    private const INGEST_HOST = 'ingest.nearbypost.com';
    private const USER_AGENT  = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    /** Older than this and it is not news, whatever the feed says. */
    private const MAX_STORY_AGE_DAYS = 2;
    /**
     * Items per submission.
     *
     * 25 meant 31 requests for a normal run, which brushes the 60/min
     * ingest limit as soon as two runs land in the same minute. Larger
     * batches do the same work in a tenth of the requests.
     */
    private const BATCH_SIZE  = 100;

    /**
     * Headlines this alike are the same story.
     *
     * Measured, not assumed: on real pairs from this database, genuine
     * duplicates score 85.5% to 91.7% and distinct stories 25% to 68.7%. The
     * threshold sits in the gap between them.
     */
    private const TITLE_SIMILARITY = 0.82;

    /** URL path fragments that indicate a listing page rather than an article. */
    private const BLOCKED_PATHS = [
        '/tag/', '/tags/', '/category/', '/categories/', '/archive', '/search',
        '/video/', '/videos/', '/gallery/', '/galleries/', '/photo/', '/photos/',
        '/topic/', '/topics/', '/live/', '/author/',
    ];

    /** Quirks observed this run, per source id. */
    private array $observedQuirks = [];

    private ContentPolicy $policy;

    public function __construct()
    {
        parent::__construct();
        $this->policy = new ContentPolicy();
    }

    /**
     * Every run leaves a row, finished or not.
     *
     * ⛔ 4 Sep 2026: the 08:00 run collected 574 stories, threw "Undefined
     * array key _aggregator", and lost all of them. Nothing said so. The stack
     * trace went to a log nobody reads, and the day's ingested count simply
     * read low - which is indistinguishable from a quiet morning. The owner
     * caught it by eye: "is the php scrapper even working today?"
     *
     * A failure has to be a ROW, not an absence. An absence is what hid it.
     */
    public function handle(): int
    {
        $runId = DB::table('fetch_runs')->insertGetId([
            'tier'       => (string) ($this->option('tier') ?: $this->option('source') ?: 'all'),
            'started_at' => now(),
            'status'     => 'crashed',   // assume the worst; the end of run() puts it right
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runId = $runId;

        try {
            return $this->fetchEverything();
        } catch (\Throwable $e) {
            DB::table('fetch_runs')->where('id', $runId)->update([
                'finished_at' => now(),
                'status'      => 'crashed',
                'error'       => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 400),
                'updated_at'  => now(),
            ]);

            Log::error('Fetch run died', ['run' => $runId, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /** The id of the row this run is being recorded in. */
    private ?int $runId = null;

    /** How many distinct stories this run found, for the row submit() writes. */
    private int $uniqueCount = 0;

    // NOT run(): Illuminate\Console\Command::run() is public, and overriding
    // it privately is a fatal error. The same trap caught ask() in the training
    // command - a base class of this size owns a lot of ordinary verbs.
    private function fetchEverything(): int
    {
        $sources = $this->selectSources();

        if ($sources->isEmpty()) {
            $this->warn('No matching active sources.');
            return 0;
        }

        $this->info("Fetching {$sources->count()} source(s).");

        $collected = [];

        foreach ($sources as $source) {
            foreach ($this->fetchSource($source) as $item) {
                $collected[] = $item;
            }
        }

        $this->info('Collected ' . count($collected) . ' items.');

        DB::table('fetch_runs')->where('id', $this->runId)->update([
            'sources'   => $sources->count(),
            'collected' => count($collected),
            'updated_at' => now(),
        ]);

        // kept on the object because submit() is a different method and cannot
        // see $unique - reading it there threw "Undefined variable $unique"
        $this->uniqueCount = 0;

        // Reconcile everything before anything is written. A story carried by
        // three feeds should reach the database once, credited to the publisher
        // rather than to whichever aggregator happened to be read first.
        [$unique, $dropped] = $this->deduplicate($collected);

        $this->info(sprintf('After de-duplication: %d unique, %d duplicate.', count($unique), $dropped));

        $this->uniqueCount = count($unique);

        $this->recordRecipes($sources);

        if ($unique === []) {
            // Genuinely nothing new. Not a fault - but only when nothing was
            // collected either. Collecting hundreds and de-duplicating to none
            // is the shape of a broken run, not a quiet one.
            DB::table('fetch_runs')->where('id', $this->runId)->update([
                'finished_at'  => now(),
                'unique_items' => 0,
                'status'       => count($collected) > 0 ? 'barren' : 'empty',
                'updated_at'   => now(),
            ]);

            return 0;
        }

        if ($this->option('dry-run')) {
            foreach (array_slice($unique, 0, 10) as $item) {
                $this->line("  [{$item['source']}] {$item['title']}");
            }
            $this->info('Dry run: nothing submitted.');

            // A dry run stores nothing ON PURPOSE, so it must not be filed as
            // a failure. Left alone it stayed at the pessimistic default and
            // reported the first false alarm this table ever raised.
            DB::table('fetch_runs')->where('id', $this->runId)->update([
                'finished_at'  => now(),
                'unique_items' => count($unique),
                'status'       => 'ok',
                'error'        => 'dry run: nothing was meant to be stored',
                'updated_at'   => now(),
            ]);

            return 0;
        }

        return $this->submit($unique);
    }

    private function selectSources()
    {
        // A source is a feed or a section page; either needs somewhere to read.
        $query = DB::table('sources')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNotNull('rss_url')->orWhereNotNull('index_url');
            });

        if ($ref = $this->option('source')) {
            return $query->where(function ($q) use ($ref) {
                $q->where('id', (int) $ref)->orWhere('name', $ref);
            })->get();
        }

        $tier = $this->option('tier');

        if ($tier !== 'all') {
            $query->where('priority_tier', $tier);
        } else {
            $query->whereIn('priority_tier', ['primary', 'secondary']);
        }

        $blocklist = new \App\Services\SourceBlocklist();

        return $query->orderBy('name')->get()
            // Told not to visit is told not to visit, whatever the schedule says.
            ->reject(fn ($s) => $blocklist->blocksSource($s))
            ->filter(fn ($s) => $this->isDue($s))
            ->values();
    }

    /**
     * Is it time to read this source again?
     *
     * Cadence used to belong to the whole tier, so a property section that
     * publishes twice a week was visited ninety-six times a day. A source with
     * its own interval is read on that interval instead; a source without one
     * keeps the tier's behaviour, so nothing changes until somebody sets one.
     *
     * The hour, where given, is Kuala Lumpur time and only applies to sources
     * read once a day or less - it is what "read the property section at 8am"
     * means.
     */
    private function isDue(object $source): bool
    {
        // An explicit --source is a deliberate instruction; honour it.
        if ($this->option('source')) {
            return true;
        }

        $interval = (int) ($source->fetch_interval_minutes ?? 0);

        if ($interval <= 0) {
            return true;
        }

        $hour = $source->fetch_at_hour;

        if ($hour !== null && $interval >= 1440) {
            // Only in the named hour, and only once inside it.
            if ((int) now()->setTimezone('Asia/Kuala_Lumpur')->format('G') !== (int) $hour) {
                return false;
            }

            return $source->last_fetched_at === null
                || \Carbon\Carbon::parse($source->last_fetched_at)->lt(now()->subMinutes(90));
        }

        // Ninety seconds of slack, because the run that reads a source and the
        // run fifteen minutes later do not start at the same second. Without
        // it, a fifteen-minute source is fourteen minutes fifty-seven seconds
        // old when its next chance comes, gets skipped, and quietly runs at
        // half the rate it was given.
        $due = now()->subMinutes($interval)->addSeconds(90);

        return $source->last_fetched_at === null
            || \Carbon\Carbon::parse($source->last_fetched_at)->lt($due);
    }

    // ── fetching ────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function fetchSource(object $source): array
    {
        if (($source->source_kind ?? 'rss') === 'index') {
            return $this->fetchIndex($source);
        }

        if (($source->source_kind ?? 'rss') === 'next_data') {
            return $this->fetchNextData($source);
        }

        if (($source->source_kind ?? 'rss') === 'events_api') {
            return $this->fetchEventsApi($source);
        }

        if (($source->source_kind ?? 'rss') === 'page_events') {
            return $this->fetchPageEvents($source);
        }

        if (($source->source_kind ?? 'rss') === 'mall_listing') {
            return $this->fetchMallListing($source);
        }

        $limit  = max(1, (int) $this->option('limit'));
        $recipe = $this->recipeOf($source);

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(25)
                ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                ->get($source->rss_url);

            if (!$response->successful()) {
                return $this->recordFailure($source, 'http_' . $response->status());
            }

            // A feed that moved is worth remembering: the next run can go
            // straight to where it actually lives.
            $final = (string) $response->effectiveUri();

            if ($final !== '' && $final !== $source->rss_url) {
                $this->noteQuirk($source->id, 'redirects_to:' . $final);
            }

            $items = $this->parseFeed($response->body(), $source, $limit);

        } catch (\Throwable $e) {
            Log::warning('Feed fetch failed', ['source' => $source->name, 'error' => $e->getMessage()]);
            return $this->recordFailure($source, 'exception');
        }

        if (!$this->option('dry-run')) {
        DB::table('sources')->where('id', $source->id)->update([
            'last_fetched_at'      => now(),
            'last_status'          => 'ok',
            'last_item_count'      => count($items),
            'consecutive_failures' => 0,

            // ⛔ A FETCH THAT WORKED AND FOUND NOTHING IS NEITHER A FAILURE NOR
            // A SUCCESS. consecutive_failures stays at zero - the server
            // answered - so nothing would ever notice a source that has quietly
            // stopped publishing. This does; sources:decay acts on it.
            'consecutive_empty'    => count($items) === 0
                ? DB::raw('consecutive_empty + 1')
                : 0,

            'updated_at'           => now(),
        ]);
        }

        $this->line(sprintf('  %-32s %3d items', $source->name, count($items)));

        return $items;
    }

    /**
     * Read a publisher who builds their pages in the browser.
     *
     * A Next.js application ships the data its page is about to render inside
     * the HTML, in a script tag called __NEXT_DATA__. For a publisher with no
     * feed and no sitemap that block is the only structured list of what they
     * have published - and it is served to every visitor as part of the page,
     * so reading it is what a browser does.
     *
     * link_pattern names which keys inside pageProps hold articles, comma
     * separated, because a publisher decides what to call its own sections:
     * The Edge uses malaysiaNews and cityCountryData, and the next one will use
     * something else.
     */
    private function fetchNextData(object $source): array
    {
        $limit = max(1, (int) $this->option('limit'));

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(25)
                ->get($source->index_url);

            if (!$response->successful()) {
                return $this->recordFailure($source, 'http_' . $response->status());
            }

            if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $response->body(), $m)) {
                // The publisher has changed how their site is built. Better to
                // say so than to return nothing and look merely quiet.
                return $this->recordFailure($source, 'no_next_data_block');
            }

            $data = json_decode($m[1], true);
            $props = $data['props']['pageProps'] ?? [];

        } catch (\Throwable $e) {
            Log::warning('Next data fetch failed', ['source' => $source->name, 'error' => $e->getMessage()]);

            return $this->recordFailure($source, 'exception');
        }

        $keys = array_filter(array_map('trim', explode(',', (string) ($source->link_pattern ?: ''))));
        $items = [];

        foreach ($keys as $key) {
            foreach ((array) ($props[$key] ?? []) as $row) {
                if (!is_array($row) || empty($row['title']) || empty($row['nid'])) {
                    continue;
                }

                $url = rtrim((string) $source->base_url, '/') . '/node/' . $row['nid'];

                if ($this->isBlockedPath($url)) {
                    continue;
                }

                // Their timestamps are milliseconds. Treating one as seconds
                // dates the story to 1970 and the pipeline drops it as stale.
                $created = isset($row['created']) ? (int) $row['created'] : null;
                $published = $created
                    ? \Carbon\Carbon::createFromTimestampMs($created)->toDateTimeString()
                    : now()->toDateTimeString();
                $this->lastPrecision = $created ? 'time' : 'scraped';

                if ($this->tooOld($published, $source)) {
                    continue;
                }

                $items[] = [
                    'title'         => mb_substr((string) $row['title'], 0, 250),
                    'url'           => $url,
                    'source'        => $this->publisherName($source),
                    'source_label'  => $this->publisherName($source),
                    'source_name'   => $this->publisherName($source),
                    'source_domain' => parse_url((string) $source->base_url, PHP_URL_HOST),
                    'summary'       => mb_substr((string) ($row['summary'] ?? ''), 0, 1000),
                    'published_at'  => $published,
                    'published_precision' => $this->lastPrecision,
                    'source_id'     => $source->id,
                ];

                if (count($items) >= $limit) {
                    break 2;
                }
            }
        }

        if ($items === []) {
            return $this->recordFailure($source, 'next_data_no_articles');
        }

        if (!$this->option('dry-run')) {
            DB::table('sources')->where('id', $source->id)->update([
                'last_fetched_at'      => now(),
                'last_status'          => 'ok',
                'last_item_count'      => count($items),
                'consecutive_failures' => 0,
                'updated_at'           => now(),
            ]);
        }

        $this->line(sprintf('  %-32s %3d items', $source->name, count($items)));

        return $items;
    }

    /**
     * Read a publisher's section page.
     *
     * Feeds are a publisher's choice, not a guarantee: The Star and Bernama both
     * advertise feeds that answer 404, so relying on RSS alone meant getting
     * nothing at all from two of the largest outlets in the country.
     *
     * Only the index is read. Links are not followed onwards, and the site is
     * not crawled recursively - these are the headlines the section page is
     * already showing.
     */
    private function fetchIndex(object $source): array
    {
        $limit = max(1, (int) $this->option('limit'));

        $links = (new IndexCrawler($this->policy))->crawl(
            $source->index_url,
            $source->link_pattern
        );

        if ($links === []) {
            return $this->recordFailure($source, 'index_no_links');
        }

        $items = [];

        foreach (array_slice($links, 0, $limit) as $link) {
            if ($this->isBlockedPath($link['url'])) {
                continue;
            }

            $items[] = [
                'title'         => $link['title'],
                'url'           => $link['url'],
                'source'        => $this->publisherName($source),
                'source_label'  => $this->publisherName($source),
                'source_name'   => $this->publisherName($source),
                'source_domain' => parse_url($source->base_url ?? $link['url'], PHP_URL_HOST),
                // A section page carries no publication time and no standfirst.
                // Extraction fetches the body and enrichment writes the summary,
                // which is the same path a feed item takes when its description
                // turns out to be a byline.
                'published_at'  => gmdate('c'),
                'summary'       => '',
                '_source_id'    => $source->id,
                '_aggregator'   => false,
            ];
        }

        if (!$this->option('dry-run')) {
        DB::table('sources')->where('id', $source->id)->update([
            'last_fetched_at'      => now(),
            'last_status'          => 'ok',
            'last_item_count'      => count($items),
            'consecutive_failures' => 0,

            // ⛔ A FETCH THAT WORKED AND FOUND NOTHING IS NEITHER A FAILURE NOR
            // A SUCCESS. consecutive_failures stays at zero - the server
            // answered - so nothing would ever notice a source that has quietly
            // stopped publishing. This does; sources:decay acts on it.
            'consecutive_empty'    => count($items) === 0
                ? DB::raw('consecutive_empty + 1')
                : 0,

            'updated_at'           => now(),
        ]);
        }

        $this->line(sprintf('  %-32s %3d items (index)', $source->name, count($items)));

        return $items;
    }

    /**
     * An events calendar that publishes a proper API, read page by page.
     *
     * The Events Calendar (the WordPress plugin behind most Malaysian listings
     * sites) exposes /wp-json/tribe/events/v1/events with `total` and
     * `total_pages`, so the whole calendar can be taken without guessing when
     * to stop.
     *
     * ⭐⭐ WHY THIS IS WORTH A SOURCE KIND OF ITS OWN: the API gives
     * start_date and end_date as fields. Everywhere else on this project an
     * event window has to be parsed out of prose, and prose lies - MyTOWN KL
     * shows "27 Aug 2026 to 6 Sep 2026" (how long the mall features the item)
     * on a festival that actually runs on the 5th and 6th. Read from an API
     * there is nothing to misread, so the window is set here and
     * SetEventWindows never has to guess.
     *
     * The venue arrives structured too - name, street, city, country - which is
     * a far better thing to hand the geocoder than a headline.
     */
    private function fetchEventsApi(object $source): array
    {
        $limit    = max(1, (int) $this->option('limit'));
        $base     = rtrim((string) $source->index_url, '?&');
        $items    = [];
        $page     = 1;
        $maxPages = 20;

        while ($page <= $maxPages && count($items) < $limit) {
            $url = $base . (str_contains($base, '?') ? '&' : '?') . 'per_page=50&page=' . $page;

            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(30)->get($url);
            } catch (\Throwable $e) {
                break;
            }

            if (!$response->successful()) {
                // Page 1 failing is a broken source; a later page failing just
                // ends the walk with what already arrived.
                if ($page === 1) {
                    return $this->recordFailure($source, 'events_api_http_' . $response->status());
                }

                break;
            }

            $body   = (array) $response->json();
            $events = $body['events'] ?? [];

            if ($events === []) {
                break;
            }

            foreach ($events as $event) {
                if (count($items) >= $limit) {
                    break;
                }

                $item = $this->eventItem($event, $source);

                if ($item !== null) {
                    $items[] = $item;
                }
            }

            $total = (int) ($body['total_pages'] ?? 1);

            if ($page >= $total) {
                break;
            }

            $page++;
        }

        if ($items === []) {
            return $this->recordFailure($source, 'events_api_no_events');
        }

        if (!$this->option('dry-run')) {
            DB::table('sources')->where('id', $source->id)->update([
                'last_fetched_at'      => now(),
                'last_status'          => 'ok',
                'last_item_count'      => count($items),
                'consecutive_failures' => 0,
                'updated_at'           => now(),
            ]);
        }

        $this->line(sprintf('  %-32s %3d items (events api, %d page%s)',
            $source->name, count($items), $page, $page === 1 ? '' : 's'));

        return $items;
    }

    /**
     * One event from the API, in the shape the rest of the pipeline expects.
     *
     * @return array<string, mixed>|null
     */
    private function eventItem(array $event, object $source): ?array
    {
        $title = trim(html_entity_decode((string) ($event['title'] ?? ''), ENT_QUOTES | ENT_HTML5));
        $url   = (string) ($event['url'] ?? '');

        if ($title === '' || $url === '') {
            return null;
        }

        // ⛔ An event that finished is not news. The calendar keeps its past
        // entries and there is no reason to pay to classify them.
        $end = $event['end_date'] ?? $event['start_date'] ?? null;

        if ($end !== null && strtotime((string) $end) < strtotime('-1 day')) {
            return null;
        }

        $venue = is_array($event['venue'] ?? null) ? $event['venue'] : [];

        // Name, street, city - the geocoder is given the address the calendar
        // holds rather than being left to read it out of a headline.
        $place = implode(', ', array_values(array_filter([
            trim((string) ($venue['venue'] ?? '')),
            trim((string) ($venue['address'] ?? '')),
            trim((string) ($venue['city'] ?? '')),
        ], fn ($p) => $p !== '')));

        $summary = trim(html_entity_decode(strip_tags((string) ($event['excerpt'] ?? $event['description'] ?? '')), ENT_QUOTES | ENT_HTML5));

        return [
            'title'         => mb_substr($title, 0, 250),
            'url'           => $url,
            'source'        => $this->publisherName($source),
            'source_label'  => $this->publisherName($source),
            'source_name'   => $this->publisherName($source),
            'source_domain' => parse_url($source->base_url ?: $url, PHP_URL_HOST),
            'published_at'  => isset($event['date']) ? date('c', strtotime((string) $event['date'])) : gmdate('c'),
            'summary'       => mb_substr(preg_replace('/\s+/u', ' ', $summary) ?? '', 0, 600),

            // ⛔ The event window is NOT sent here. The ingest endpoint
            // validates strictly and drops what it does not know, so a field
            // added to this payload would vanish without a word - the same
            // whitelist trap that has bitten this project three times.
            // events:sync-windows stamps the dates afterwards, by URL, where a
            // row that cannot be found is reported rather than lost.

            '_source_id'    => $source->id,
            '_aggregator'   => false,
        ];
    }

    /**
     * A listing page whose events are embedded as JSON rather than linked.
     *
     * ⛔ THE EVENTS WERE THERE ALL ALONG AND A LINK COUNT SAID THEY WERE NOT.
     *
     * KLCC's homepage was first written off as "no event links" because every
     * check counted anchors. The cards are not anchors: the whole calendar sits
     * in a "pages" JSON block inside the markup, escaped into a JS string, with
     * a name, a thumbnail, an address and two dates per entry. The owner looked
     * at the page, saw six events, and asked. He was right.
     *
     * ⭐ The dates come as fields, so the window is read rather than parsed -
     * the same advantage the Tribe API gives, from a page that looked unusable.
     *
     * ⛔ The address on each entry points AWAY from the venue: ticket2u, luma,
     * an organiser's own domain. That is genuinely where a reader goes to book,
     * so it is kept as the link - but it means extraction will fetch a third
     * party, and the venue is never in that page. The venue is always this
     * source's own, which is why `section` carries it.
     */
    private function fetchPageEvents(object $source): array
    {
        $limit = max(1, (int) $this->option('limit'));

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(30)->get($source->index_url);
        } catch (\Throwable $e) {
            return $this->recordFailure($source, 'page_events_unreachable');
        }

        if (!$response->successful()) {
            return $this->recordFailure($source, 'page_events_http_' . $response->status());
        }

        foreach ($this->pageEventRows($response->body()) as $row) {
            if (count($items ??= []) >= $limit) {
                break;
            }

            $items[] = [
                'title'         => mb_substr($row['title'], 0, 250),
                'url'           => $row['url'],
                'source'        => $this->publisherName($source),
                'source_label'  => $this->publisherName($source),
                'source_name'   => $this->publisherName($source),
                'source_domain' => parse_url($source->base_url ?: $row['url'], PHP_URL_HOST),
                'published_at'  => gmdate('c'),

                // The venue is this source's own and never appears in the page
                // the link goes to, so it is stated here for the geocoder.
                'summary'       => trim($row['when'] . '. ' . (string) ($source->section ?: '')),

                '_source_id'    => $source->id,
                '_aggregator'   => false,
            ];
        }

        $items ??= [];

        if ($items === []) {
            return $this->recordFailure($source, 'page_events_none');
        }

        if (!$this->option('dry-run')) {
            DB::table('sources')->where('id', $source->id)->update([
                'last_fetched_at'      => now(),
                'last_status'          => 'ok',
                'last_item_count'      => count($items),
                'consecutive_failures' => 0,
                'updated_at'           => now(),
            ]);
        }

        $this->line(sprintf('  %-32s %3d items (page events)', $source->name, count($items)));

        return $items;
    }

    /**
     * Pull the embedded calendar out of a page.
     *
     * @return list<array{title: string, url: string, start: ?string, end: ?string, when: string}>
     */
    public function pageEventRows(string $html): array
    {
        // The block is JSON escaped into a JavaScript string literal.
        $plain = str_replace('\\"', '"', $html);

        if (!preg_match_all('/"pages"\s*:\s*\{"0"\s*:\s*(\[.*?\])\s*\}/s', $plain, $m)) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ($m[1] as $block) {
            $rows = json_decode($block, true);

            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $title = trim(html_entity_decode((string) ($row['Page Name'] ?? ''), ENT_QUOTES | ENT_HTML5));
                $url   = trim((string) ($row['Page Address'] ?? ''));

                if ($title === '' || $url === '' || isset($seen[$url])) {
                    continue;
                }

                // ⛔ The date columns are CMS GUIDs, not names - they would
                // change if the page were rebuilt. Found by SHAPE instead:
                // any value that reads as a date is one.
                $dates = [];

                foreach ($row as $value) {
                    if (is_string($value) && preg_match('/^[A-Z][a-z]{2} \d{1,2}, \d{4}/', $value)) {
                        $t = strtotime($value);

                        if ($t !== false) {
                            $dates[] = date('Y-m-d', $t);
                        }
                    }
                }

                $dates = array_values(array_unique($dates));
                sort($dates);

                $seen[$url] = true;

                $out[] = [
                    'title' => $title,
                    'url'   => $url,
                    'start' => $dates[0] ?? null,
                    'end'   => $dates ? end($dates) : null,
                    'when'  => $dates
                        ? (count($dates) > 1 ? $dates[0] . ' to ' . end($dates) : $dates[0])
                        : '',
                ];
            }
        }

        return $out;
    }

    /**
     * A mall's What's On board: name, location, dates - and no links at all.
     *
     * ⛔ THIS PAGE WAS WRITTEN OFF TWICE BEFORE IT WAS READ PROPERLY.
     *
     * First for having no event links (there are none - nothing on the board is
     * clickable), then for being "30 KB of navigation" - which came from
     * printing only the first 300 characters of the text and seeing the menu.
     * The whole board is in the served HTML, as plain text triples:
     *
     *     Mid Autumn 2026
     *     Location:  North Court (NC), Ground Floor
     *     Date:      4 Sep - 25 Sep 2026
     *
     * ⭐ The location is better than most sources ever give - the hall and the
     * floor, not just the mall - so it is put in the summary where enrichment
     * and the geocoder will read it.
     *
     * ⛔ WITH NO LINK, A URL HAS TO BE MADE. news_items.url is unique and is
     * what de-duplication turns on, so every item cannot share the board's
     * address. A fragment built from the title is used: it is stable across
     * runs, it is honest (that IS the page the listing is on), and two
     * different promotions cannot collide.
     */
    private function fetchMallListing(object $source): array
    {
        $limit = max(1, (int) $this->option('limit'));

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(30)->get($source->index_url);
        } catch (\Throwable $e) {
            return $this->recordFailure($source, 'mall_listing_unreachable');
        }

        if (!$response->successful()) {
            return $this->recordFailure($source, 'mall_listing_http_' . $response->status());
        }

        $rows  = $this->mallListingRows($response->body());
        $items = [];

        foreach ($rows as $row) {
            if (count($items) >= $limit) {
                break;
            }

            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($row['title'])) ?? '', '-');

            if ($slug === '') {
                continue;
            }

            $where = $row['location'] !== '' ? $row['location'] . ', ' . (string) $source->section : (string) $source->section;

            $items[] = [
                'title'         => mb_substr($row['title'], 0, 250),
                'url'           => rtrim((string) $source->index_url, '/') . '/#' . $slug,
                'source'        => $this->publisherName($source),
                'source_label'  => $this->publisherName($source),
                'source_name'   => $this->publisherName($source),
                'source_domain' => parse_url((string) $source->base_url, PHP_URL_HOST),
                'published_at'  => gmdate('c'),
                'summary'       => mb_substr(trim($row['dates'] . '. ' . $where, ' .'), 0, 600),
                '_source_id'      => $source->id,
                '_aggregator'     => false,

                // Every item on this board shares one address; the fragment is
                // what makes it its own. See urlKey().
                '_fragment_items' => true,
            ];
        }

        if ($items === []) {
            return $this->recordFailure($source, 'mall_listing_none');
        }

        if (!$this->option('dry-run')) {
            DB::table('sources')->where('id', $source->id)->update([
                'last_fetched_at'      => now(),
                'last_status'          => 'ok',
                'last_item_count'      => count($items),
                'consecutive_failures' => 0,
                'updated_at'           => now(),
            ]);
        }

        $this->line(sprintf('  %-32s %3d items (mall listing)', $source->name, count($items)));

        return $items;
    }

    /**
     * Title / Location / Date triples out of a board.
     *
     * @return list<array{title: string, location: string, dates: string}>
     */
    public function mallListingRows(string $html): array
    {
        $body = preg_replace('#<(script|style|nav|header|footer)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<[^>]+>#', "\n", $body) ?? $body;
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5);

        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

            if ($line !== '' && $line !== '×') {
                $lines[] = $line;
            }
        }

        $out = [];

        foreach ($lines as $i => $line) {
            // A date line is the anchor: walk back for its label and title.
            if (!preg_match('/^Date:?$/i', $line) || !isset($lines[$i + 1])) {
                continue;
            }

            $dates = $lines[$i + 1];

            // Location: sits two lines above the value, title above that.
            $location = '';
            $title    = '';

            if (isset($lines[$i - 1]) && preg_match('/^Location:?$/i', $lines[$i - 2] ?? '')) {
                $location = $lines[$i - 1];
                $title    = $lines[$i - 3] ?? '';
            } else {
                $title = $lines[$i - 1] ?? '';
            }

            $title = trim($title);

            // A label is not a title, and neither is a date.
            if ($title === '' || mb_strlen($title) < 4
                || preg_match('/^(date|location|select by date|clear filter|all|home|what.s on|this week)$/i', $title)) {
                continue;
            }

            $out[] = [
                'title'    => $title,
                'location' => trim($location),
                'dates'    => trim($dates),
            ];
        }

        return $out;
    }

    /**
     * Credit the publication, not the section.
     * "The Star - Business" is a crawl target; the reader sees "The Star".
     */
    private function publisherName(object $source): string
    {
        return trim(explode(' - ', $source->name)[0]);
    }

    private function recordFailure(object $source, string $status): array
    {
        if (!$this->option('dry-run')) {
        DB::table('sources')->where('id', $source->id)->update([
            'last_fetched_at'      => now(),
            'last_status'          => $status,
            'last_item_count'      => 0,
            'consecutive_failures' => DB::raw('consecutive_failures + 1'),
            'updated_at'           => now(),
        ]);
        }

        $this->noteQuirk($source->id, 'failure:' . $status);
        $this->warn(sprintf('  %-32s FAILED (%s)', $source->name, $status));

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function parseFeed(string $body, object $source, int $limit): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

        // Mothership's feed carries a bare "&" in a title (xmlParseEntityRef:
        // no name) and answered 0 items for it. Escape the ampersands that are
        // not entities and read it again; the feed is otherwise sound.
        if ($xml === false) {
            libxml_clear_errors();
            $repaired = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $body);
            $xml      = simplexml_load_string((string) $repaired, 'SimpleXMLElement', LIBXML_NOCDATA);

            if ($xml !== false) {
                $this->line("  {$source->name}: feed had a bare ampersand; repaired");
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $this->noteQuirk($source->id, 'unparseable_xml');
            return [];
        }

        // RSS puts items under channel; Atom uses top-level entries.
        $entries = $xml->channel->item ?? $xml->entry ?? [];
        $items   = [];

        foreach ($entries as $entry) {
            if (count($items) >= $limit) {
                break;
            }

            $url = trim((string) ($entry->link['href'] ?? $entry->link ?? $entry->guid ?? ''));

            if ($url === '' || $this->isBlockedPath($url)) {
                continue;
            }

            $title = $this->cleanText((string) ($entry->title ?? ''));

            if ($title === '') {
                continue;
            }

            $rawSummary = $this->cleanText((string) ($entry->description ?? $entry->summary ?? ''));

            // Most publishers put the whole article in <content:encoded>, and
            // it is the only copy we can get from the ones that render in the
            // browser or refuse our crawler. Kept now; extraction takes it
            // rather than going back out to the web.
            $this->keepFeedText($url, (string) $title, $entry, $source);

            // An index of stories is not a story. Rejecting it here means it is
            // never stored, never classified and never served.
            if ($this->policy->isListingPage($title, $rawSummary)) {
                $this->noteQuirk($source->id, 'listing_pages_present');
                continue;
            }

            $publisher = $this->publisherOf($entry);

            if ($publisher !== null) {
                $this->notePublisher($publisher, $source->name);
            }

            $credit    = $publisher['name'] ?? $source->name;
            $rawDate   = (string) ($entry->pubDate ?? $entry->published ?? $entry->updated ?? '');
            $published = $this->normalisePublishedAt($rawDate, $source);

            if ($this->tooOld($published, $source)) {
                continue;
            }

            // ⛔ THEIR ID, NOT OUR URL. A publisher's <guid> does not move when
            // they change a slug, append a campaign parameter, or serve the
            // same story from two paths - each of which arrives as a second
            // copy under URL de-duplication alone.
            $externalId = trim((string) ($entry->guid ?? $entry->id ?? ''));

            $items[] = [
                // news_items.title is varchar(255).
                'title'         => mb_substr($title, 0, 250),
                'external_id'   => $externalId === '' ? null : mb_substr($externalId, 0, 200),
                'url'           => $url,
                'source'        => $credit,
                'source_label'  => $credit,
                'source_name'   => $credit,
                'source_domain' => parse_url($source->base_url ?? $url, PHP_URL_HOST),
                'published_at'  => $published,
                'published_precision' => $this->lastPrecision,
                // Publishing-system leftovers - bylines, desk emails, CMS node
                // references - are stripped rather than treated as grounds to
                // reject the article. Enrichment writes a real summary anyway.
                'summary'       => mb_substr((string) $this->policy->cleanSummary($rawSummary), 0, 800),
                // No category is guessed here. Classification belongs to the AI
                // enrichment stage, which validates against a controlled list.

                // Kept for reconciliation, stripped before submission.
                '_source_id'    => $source->id,
                '_aggregator'   => ($source->source_type ?? '') === 'aggregator',
            ];
        }

        return $items;
    }

    /**
     * The publisher an aggregator credits for this item, if it names one.
     * Returns ['name' => string, 'homepage' => string] or null.
     */
    /**
     * Store the full article text a feed item carried, if it carried one.
     *
     * <content:encoded> is an RSS extension in the "content" namespace, so it
     * is not reachable as $entry->content - it has to be asked for by
     * namespace, which is why it went unnoticed for so long.
     */
    private function keepFeedText(string $url, string $title, \SimpleXMLElement $entry, object $source): void
    {
        $encoded = '';

        foreach ($entry->children('http://purl.org/rss/1.0/modules/content/') as $name => $node) {
            if ($name === 'encoded') {
                $encoded = (string) $node;
                break;
            }
        }

        // Atom puts it in <content>, in the default namespace.
        if ($encoded === '') {
            $encoded = (string) ($entry->content ?? '');
        }

        $text = $this->cleanText($encoded);

        // Shorter than the summary we already have is not worth a row.
        if (mb_strlen($text) < 400) {
            return;
        }

        DB::table('feed_contents')->updateOrInsert(
            ['url_hash' => sha1($url)],
            [
                'url'        => mb_substr($url, 0, 990),
                'text'       => mb_substr($text, 0, 60000),
                'source'     => mb_substr($source->name ?? '', 0, 250),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function publisherOf(\SimpleXMLElement $entry): ?array
    {
        if (!isset($entry->source)) {
            return null;
        }

        $name = trim((string) $entry->source);
        $home = trim((string) ($entry->source['url'] ?? ''));

        if ($name === '' || $home === '' || !str_starts_with($home, 'http')) {
            return null;
        }

        return ['name' => $name, 'homepage' => rtrim($home, '/')];
    }

    /**
     * Queue a publisher sighting for the discovery command to drain.
     * Held in the cache so fetching stays fast and probing runs on its own
     * schedule rather than inline with ingestion.
     */
    private function notePublisher(array $publisher, string $via): void
    {
        $seen = cache()->get('ingest:publisher_sightings', []);
        $key  = $publisher['homepage'];

        if (isset($seen[$key])) {
            $seen[$key]['count']++;
        } else {
            $seen[$key] = ['name' => $publisher['name'], 'count' => 1, 'via' => $via];
        }

        cache()->put('ingest:publisher_sightings', $seen, now()->addDay());
    }

    /**
     * Correct publication times that cannot be true.
     *
     * Harian Metro and Berita Harian stamp Malaysian local time but label the
     * offset +0000, which places every one of their stories eight hours in the
     * future - enough to sort them above genuine breaking news for ever, and to
     * render as "5 hours from now". Malay Mail, by contrast, labels +0800
     * correctly, so this cannot be applied per publisher by name; it has to be
     * judged per timestamp. Where it is judged, it is recorded on the source.
     */
    /**
     * How precise the last normalised date was: 'time', 'date' or 'scraped'.
     *
     * A feed that gives "2026-09-02" has told us the day and nothing else.
     * Stored as midnight it looked like a time, was shown as "8 hours ago" at
     * breakfast, and sorted to the bottom of its own day. A feed that gives
     * nothing at all leaves us the moment we fetched it, which is not news
     * time either. The card downstream says which it is.
     */
    private string $lastPrecision = 'time';

    private function normalisePublishedAt(string $raw, object $source): string
    {
        $now = time();
        $raw = trim($raw);

        // A bare number is an epoch - seconds, or milliseconds when it is
        // thirteen digits long. strtotime() cannot read one.
        $ts = preg_match('/^\d{9,13}$/', $raw)
            ? (int) (strlen($raw) >= 13 ? substr($raw, 0, 10) : $raw)
            : ($raw !== '' ? strtotime($raw) : false);

        if ($ts === false) {
            $this->lastPrecision = 'scraped';

            return gmdate('c', $now);
        }

        // A clock, or only a calendar? "Tue, 02 Sep 2026 14:05:00 +0800" has
        // one; "2026-09-02" and "2 September 2026" do not. An epoch number is
        // a clock by definition.
        $this->lastPrecision = (preg_match('/\d{1,2}:\d{2}/', $raw) || preg_match('/^\d{9,13}$/', trim($raw))) ? 'time' : 'date';

        if ($ts > $now + 900) {
            $corrected = $ts - (8 * 3600);
            $plausible = $corrected <= $now + 900 && $corrected > $now - (14 * 86400);

            if ($plausible) {
                $this->noteQuirk($source->id, 'published_at_local_labelled_utc');
                $ts = $corrected;
            } else {
                $this->noteQuirk($source->id, 'published_at_implausible');
                $ts = $now;
            }
        }

        return gmdate('c', $ts);
    }

    // ── reconciliation ──────────────────────────────────────────────────

    /**
     * Collapse the same story arriving from several feeds.
     *
     * Two stories are the same when they share a URL, or when their headlines
     * reduce to the same text once the publisher suffix, section tag and
     * punctuation are removed. Aggregators rewrite neither, so this catches the
     * common case of one article arriving both directly and via an aggregator.
     *
     * The survivor is chosen, not taken at random: a direct publisher beats an
     * aggregator, because its URL points at the article rather than at a
     * redirect, and a longer summary beats a shorter one.
     *
     * @return array{0: list<array<string,mixed>>, 1: int}
     */
    private function deduplicate(array $items): array
    {
        /** @var list<array<string,mixed>> $unique */
        $unique = [];

        /** @var array<string,int> $slotOf key => index into $unique */
        $slotOf  = [];

        /** @var array<string,list<int>> $tokenIndex token => slots containing it */
        $tokenIndex = [];

        $dropped = 0;

        foreach ($items as $item) {
            $keys = [
                $this->urlKey($item['url'], !empty($item['_fragment_items'])),
                'T:' . $this->titleKey($item['title']),
            ];

            $slot = null;

            foreach ($keys as $key) {
                if (isset($slotOf[$key])) {
                    $slot = $slotOf[$key];
                    break;
                }
            }

            // Publishers rarely repeat each other word for word. "Escaped
            // Sungai Buloh prisoner recaptured" and "...detainee recaptured"
            // were both live as separate stories; one word apart.
            if ($slot === null) {
                $slot = $this->findSimilar($item['title'], $unique, $tokenIndex);
            }

            if ($slot !== null) {
                if ($this->prefer($item, $unique[$slot])) {
                    $unique[$slot] = $item;
                }

                // Both spellings of this story now lead to the same slot, so a
                // third copy matching either one is recognised too.
                foreach ($keys as $key) {
                    $slotOf[$key] = $slot;
                }

                $this->countDuplicate($item['_source_id']);
                $dropped++;

                continue;
            }

            $unique[] = $item;
            $slot = array_key_last($unique);

            foreach ($keys as $key) {
                $slotOf[$key] = $slot;
            }

            foreach ($this->significantTokens($item['title']) as $token) {
                $tokenIndex[$token][] = $slot;
            }
        }

        return [array_values($unique), $dropped];
    }

    private function prefer(array $candidate, array $incumbent): bool
    {
        // A direct publisher URL beats an aggregator redirect.
        //
        // ⛔ Not every path that builds an item sets this flag, and reading it
        // directly threw "Undefined array key" - which aborted the WHOLE fetch
        // run, losing every source after the one that tripped it. An item with
        // no flag is treated as a direct publisher, which is what it is.
        $candidateAgg = (bool) ($candidate['_aggregator'] ?? false);
        $incumbentAgg = (bool) ($incumbent['_aggregator'] ?? false);

        if ($candidateAgg !== $incumbentAgg) {
            return !$candidateAgg;
        }

        return mb_strlen($candidate['summary'] ?? '') > mb_strlen($incumbent['summary'] ?? '');
    }

    /**
     * The slot holding a story whose headline is near-identical to this one.
     *
     * Only titles sharing at least three significant tokens are compared, which
     * keeps this close to linear on a run of several hundred items while still
     * catching anything similar enough to be the same story.
     */
    private function findSimilar(string $title, array $unique, array $tokenIndex): ?int
    {
        $tokens = $this->significantTokens($title);

        if (count($tokens) < 3) {
            return null;
        }

        $counts = [];

        foreach ($tokens as $token) {
            foreach ($tokenIndex[$token] ?? [] as $slot) {
                $counts[$slot] = ($counts[$slot] ?? 0) + 1;
            }
        }

        arsort($counts);

        foreach ($counts as $slot => $shared) {
            if ($shared < 3) {
                break;
            }

            if ($this->titleSimilarity($title, $unique[$slot]['title']) >= self::TITLE_SIMILARITY) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * How alike two headlines read.
     *
     * Character-based rather than set-based. Token overlap looked like the
     * natural choice and scored a genuine duplicate at 0.75, because swapping
     * one word out of eight costs a set comparison far more than it costs a
     * reader's recognition.
     */
    private function titleSimilarity(string $a, string $b): float
    {
        $a = $this->titleKey($a);
        $b = $this->titleKey($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /** Words worth comparing: the short ones carry no identity. */
    private function significantTokens(string $title): array
    {
        $text = $this->titleKey($title);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_filter($words, fn ($w) => mb_strlen($w) > 3);

        return array_values(array_unique($tokens));
    }

    private function urlKey(string $url, bool $keepFragment = false): string
    {
        $url = mb_strtolower(trim($url));

        // ⛔ A FRAGMENT IS NORMALLY THE SAME PAGE, AND SOMETIMES IT IS THE ITEM.
        //
        // Stripping it is right almost everywhere: a story linked once plainly
        // and once with #comments is one story. But a mall's What's On board
        // has no per-event pages at all - seven promotions live on one address,
        // and the fragment is the only thing telling them apart. Stripping it
        // collapsed all seven into one and reported six as duplicates.
        //
        // The board address is still the honest link for a reader: that IS
        // where the listing is shown. So the URL keeps its fragment and only
        // the sources that need it say so.
        $pattern = $keepFragment ? '/\?.*$/' : '/[#?].*$/';

        return 'U:' . preg_replace($pattern, '', $url);
    }

    /** A headline reduced to its words, for comparison across publishers. */
    private function titleKey(string $title): string
    {
        $text = mb_strtolower($title);

        // "Story headline - The Star" and "#SHOWBIZ: Story headline"
        $text = preg_replace('/\s+[-|]\s+[^-|]{2,40}$/u', '', $text);
        $text = preg_replace('/^#?[a-z]+\s*:\s*/u', '', $text);

        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    // ── learned behaviour ───────────────────────────────────────────────

    private function recipeOf(object $source): array
    {
        $recipe = json_decode((string) ($source->fetch_recipe ?? ''), true);

        return is_array($recipe) ? $recipe : [];
    }

    private function noteQuirk(int $sourceId, string $quirk): void
    {
        $this->observedQuirks[$sourceId][] = $quirk;
    }

    /**
     * Write what this run learned back to each source.
     *
     * Recorded rather than merely logged, because a quirk in a log is knowledge
     * only the person reading the log has; a quirk on the source row is
     * knowledge the next run inherits.
     */
    private function recordRecipes($sources): void
    {
        if ($this->option('dry-run')) {
            return;
        }

        foreach ($sources as $source) {
            $observed = array_values(array_unique($this->observedQuirks[$source->id] ?? []));

            if ($observed === []) {
                continue;
            }

            $recipe = $this->recipeOf($source);

            $recipe['transport'] = [
                'follow_redirects' => true,
                'user_agent'       => 'nearbypost',
            ];

            $recipe['quirks'] = array_values(array_unique(
                array_merge($recipe['quirks'] ?? [], $observed)
            ));

            $recipe['observed'] = [
                'last_seen_at'  => now()->toAtomString(),
                'typical_items' => $source->last_item_count,
            ];

            DB::table('sources')->where('id', $source->id)->update([
                'fetch_recipe' => json_encode($recipe, JSON_UNESCAPED_SLASHES),
                'updated_at'   => now(),
            ]);
        }
    }

    private function countDuplicate(?int $sourceId): void
    {
        if ($sourceId === null || $this->option('dry-run')) {
            return;
        }

        DB::table('sources')->where('id', $sourceId)->update([
            'items_duplicate' => DB::raw('items_duplicate + 1'),
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Is this a section or listing page rather than an article?
     *
     * Matching the blocked fragments against the whole URL is too blunt: Free
     * Malaysia Today publishes articles at
     * /category/nation/2026/08/31/some-slug, so a bare '/category/' test threw
     * away every FMT article. An article is recognised first - by a dated path
     * segment or a hyphenated slug - and only then are the listing fragments
     * considered.
     */
    /**
     * Is this story too old to be news?
     *
     * A feed answering 200 with fifty fresh-looking items is not proof the
     * items are fresh. World Athletics was serving articles from MAY 2021 and
     * had been for as long as anyone had looked; Digital News Asia went back to
     * 2017. Both arrived every run, were classified at full price, and were
     * published to a site whose entire promise is what is happening near you
     * now.
     *
     * Two days rather than one. "Only today" sounds right and throws away a
     * story filed at 23:50 that a feed carries at 00:10, and a publisher whose
     * timestamps are in another timezone loses a day for nothing.
     *
     * Event sources are exempt: their whole point is a date in the future, and
     * a concert announced in July for December is not stale.
     */
    private function tooOld(?string $published, object $source): bool
    {
        if (!empty($source->is_event_source)) {
            return false;
        }

        if ($published === null || $published === '') {
            return false;   // no date is not evidence of an old one
        }

        try {
            $when = \Carbon\Carbon::parse($published);
        } catch (\Throwable $e) {
            return false;
        }

        // A date in the future is a broken timestamp, not tomorrow's news, but
        // it is also not old - let it through and be judged on its content.
        if ($when->isFuture()) {
            return false;
        }

        return $when->lt(now()->subDays(self::MAX_STORY_AGE_DAYS));
    }

    private function isBlockedPath(string $url): bool
    {
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($path === '' || $path === '/') {
            return true;
        }

        $looksLikeArticle = preg_match('#/\d{4}/\d{2}/#', $path)
            || preg_match('#/[a-z0-9]+(?:-[a-z0-9]+){2,}/?$#', $path);

        if ($looksLikeArticle) {
            return false;
        }

        foreach (self::BLOCKED_PATHS as $fragment) {
            if (str_contains($path . '/', $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function cleanText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text ?? '');

        return trim($text ?? '');
    }

    private function submit(array $items): int
    {
        $created = $duplicate = $rejected = 0;
        $contributed = [];

        foreach (array_chunk($items, self::BATCH_SIZE) as $chunk) {
            $sourceIds = array_column($chunk, '_source_id');

            // Strip the reconciliation fields; the endpoint validates strictly.
            $payload = array_map(function (array $item) {
                unset($item['_source_id'], $item['_aggregator'], $item['_fragment_items']);
                return $item;
            }, $chunk);

            try {
                $response = Http::withHeaders([
                    'Host'         => self::INGEST_HOST,
                    'Content-Type' => 'application/json',
                ])
                    ->timeout(90)
                    ->post(self::INGEST_URL, ['items' => array_values($payload)]);

                if (!$response->successful()) {
                    $this->error('  batch rejected: HTTP ' . $response->status());
                    Log::error('Ingest batch rejected', [
                        'status' => $response->status(),
                        'body'   => mb_substr($response->body(), 0, 500),
                    ]);
                    continue;
                }

                foreach ((array) $response->json('results', []) as $i => $result) {
                    if (!($result['success'] ?? false)) {
                        $rejected++;
                    } elseif ($result['duplicate'] ?? false) {
                        $duplicate++;
                    } else {
                        $created++;
                        $id = $sourceIds[$i] ?? null;
                        if ($id !== null) {
                            $contributed[$id] = ($contributed[$id] ?? 0) + 1;
                        }
                    }
                }

            } catch (\Throwable $e) {
                $this->error('  batch failed: ' . $e->getMessage());
                Log::error('Ingest batch failed', ['error' => $e->getMessage()]);
            }
        }

        foreach ($contributed as $sourceId => $count) {
            DB::table('sources')->where('id', $sourceId)->update([
                'items_contributed' => DB::raw('items_contributed + ' . (int) $count),
            ]);

            // Kept per day and per source, so "how much is this giving me now"
            // has an answer that can go down as well as up - and one that
            // survives the stories themselves being deleted.
            DB::statement(
                'INSERT INTO source_daily_stats (source_id, day, items_new, created_at, updated_at)
                 VALUES (?, CURRENT_DATE, ?, NOW(), NOW())
                 ON CONFLICT (source_id, day)
                 DO UPDATE SET items_new = source_daily_stats.items_new + EXCLUDED.items_new,
                               updated_at = NOW()',
                [$sourceId, (int) $count]
            );
        }

        $this->info("Done. new={$created} already-held={$duplicate} rejected={$rejected}");

        DB::table('fetch_runs')->where('id', $this->runId)->update([
            'finished_at'  => now(),
            'unique_items' => $this->uniqueCount,
            'created'      => $created,
            'duplicate'    => $duplicate,
            // Storing nothing from something collected is the failure this
            // table exists for. Everything already held is a normal quiet run.
            'status'       => ($created === 0 && $duplicate === 0 && $this->uniqueCount > 0) ? 'barren' : 'ok',
            'updated_at'   => now(),
        ]);

        return 0;
    }
}

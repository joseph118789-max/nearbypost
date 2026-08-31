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

    public function handle(): int
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

        // Reconcile everything before anything is written. A story carried by
        // three feeds should reach the database once, credited to the publisher
        // rather than to whichever aggregator happened to be read first.
        [$unique, $dropped] = $this->deduplicate($collected);

        $this->info(sprintf('After de-duplication: %d unique, %d duplicate.', count($unique), $dropped));

        $this->recordRecipes($sources);

        if ($unique === []) {
            return 0;
        }

        if ($this->option('dry-run')) {
            foreach (array_slice($unique, 0, 10) as $item) {
                $this->line("  [{$item['source']}] {$item['title']}");
            }
            $this->info('Dry run: nothing submitted.');
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

        return $query->orderBy('name')->get();
    }

    // ── fetching ────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function fetchSource(object $source): array
    {
        if (($source->source_kind ?? 'rss') === 'index') {
            return $this->fetchIndex($source);
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

        DB::table('sources')->where('id', $source->id)->update([
            'last_fetched_at'      => now(),
            'last_status'          => 'ok',
            'last_item_count'      => count($items),
            'consecutive_failures' => 0,
            'updated_at'           => now(),
        ]);

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

        DB::table('sources')->where('id', $source->id)->update([
            'last_fetched_at'      => now(),
            'last_status'          => 'ok',
            'last_item_count'      => count($items),
            'consecutive_failures' => 0,
            'updated_at'           => now(),
        ]);

        $this->line(sprintf('  %-32s %3d items (index)', $source->name, count($items)));

        return $items;
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
        DB::table('sources')->where('id', $source->id)->update([
            'last_fetched_at'      => now(),
            'last_status'          => $status,
            'last_item_count'      => 0,
            'consecutive_failures' => DB::raw('consecutive_failures + 1'),
            'updated_at'           => now(),
        ]);

        $this->noteQuirk($source->id, 'failure:' . $status);
        $this->warn(sprintf('  %-32s FAILED (%s)', $source->name, $status));

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function parseFeed(string $body, object $source, int $limit): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
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

            $items[] = [
                // news_items.title is varchar(255).
                'title'         => mb_substr($title, 0, 250),
                'url'           => $url,
                'source'        => $credit,
                'source_label'  => $credit,
                'source_name'   => $credit,
                'source_domain' => parse_url($source->base_url ?? $url, PHP_URL_HOST),
                'published_at'  => $published,
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
    private function normalisePublishedAt(string $raw, object $source): string
    {
        $now = time();
        $ts  = $raw !== '' ? strtotime($raw) : false;

        if ($ts === false) {
            return gmdate('c', $now);
        }

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
                $this->urlKey($item['url']),
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
        if ($candidate['_aggregator'] !== $incumbent['_aggregator']) {
            return !$candidate['_aggregator'];
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

    private function urlKey(string $url): string
    {
        return 'U:' . preg_replace('/[#?].*$/', '', mb_strtolower(trim($url)));
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
                unset($item['_source_id'], $item['_aggregator']);
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
        }

        $this->info("Done. new={$created} already-held={$duplicate} rejected={$rejected}");

        return 0;
    }
}

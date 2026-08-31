<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetch RSS feeds and hand them to the ingestion endpoint.
 *
 * This replaces both the n8n workflow and scripts/rss_ingest_recovery.php.
 *
 * Two faults in the script it replaces are worth remembering, because both were
 * silent:
 *
 *   - It fetched with simplexml_load_file(), which follows no redirects and
 *     sends no user agent. Two of its three feeds answered 301 and 404, so for
 *     months every article on the site came from the one remaining source and
 *     nothing reported a problem.
 *   - It hard-coded primary_category = 'nation' on every item, which is why
 *     that value dominates news_items to this day. Categories belong to the AI
 *     enrichment stage; ingestion should not guess them.
 *
 * Feeds are read from the `sources` table so they can be added, disabled or
 * re-tiered without a deploy, and every fetch records its outcome against the
 * source that produced it.
 *
 * Items are posted to the existing ingest endpoint rather than written directly,
 * so validation, URL policy, de-duplication and failed-ingest capture all stay
 * in one place. The request goes to nginx on loopback, so it never depends on a
 * development server being alive.
 */
class FetchNewsFeeds extends Command
{
    protected $signature = 'ingest:fetch
        {--source= : Fetch a single source by id or name}
        {--tier=primary : Which priority tier to fetch (primary, secondary, all)}
        {--limit=60 : Max items to take from any one feed}
        {--dry-run : Fetch and report, but do not send anything}';

    protected $description = 'Fetch RSS feeds listed in the sources table and submit them for ingestion';

    private const INGEST_URL  = 'http://127.0.0.1:8080/api/internal/ingest/batch';
    private const INGEST_HOST = 'ingest.nearbypost.com';
    private const USER_AGENT  = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';
    private const BATCH_SIZE  = 25;

    /** URL path fragments that indicate a listing page rather than an article. */
    private const BLOCKED_PATHS = [
        '/tag/', '/tags/', '/category/', '/categories/', '/archive', '/search',
        '/video/', '/videos/', '/gallery/', '/galleries/', '/photo/', '/photos/',
        '/topic/', '/topics/', '/live/', '/author/',
    ];

    public function handle(): int
    {
        $sources = $this->selectSources();

        if ($sources->isEmpty()) {
            $this->warn('No matching active sources.');
            return 0;
        }

        $this->info("Fetching {$sources->count()} source(s).");

        $collected = [];
        $seenUrls  = [];

        foreach ($sources as $source) {
            $items = $this->fetchSource($source, $seenUrls);

            foreach ($items as $item) {
                $collected[] = $item;
            }
        }

        $this->info('Collected ' . count($collected) . ' unique items.');

        if ($collected === []) {
            return 0;
        }

        if ($this->option('dry-run')) {
            foreach (array_slice($collected, 0, 10) as $item) {
                $this->line("  [{$item['source']}] {$item['title']}");
            }
            $this->info('Dry run: nothing submitted.');
            return 0;
        }

        return $this->submit($collected);
    }

    private function selectSources()
    {
        $query = DB::table('sources')->where('is_active', true)->whereNotNull('rss_url');

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

    /** @return list<array<string,mixed>> */
    private function fetchSource(object $source, array &$seenUrls): array
    {
        $limit = max(1, (int) $this->option('limit'));

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(25)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($source->rss_url);

            if (!$response->successful()) {
                return $this->recordFailure($source, 'http_' . $response->status());
            }

            $items = $this->parseFeed($response->body(), $source, $seenUrls, $limit);

        } catch (\Throwable $e) {
            Log::warning('Feed fetch failed', [
                'source' => $source->name,
                'error'  => $e->getMessage(),
            ]);

            return $this->recordFailure($source, 'exception');
        }

        DB::table('sources')->where('id', $source->id)->update([
            'last_fetched_at'      => now(),
            'last_status'          => 'ok',
            'last_item_count'      => count($items),
            'consecutive_failures' => 0,
            'updated_at'           => now(),
        ]);

        $this->line(sprintf('  %-22s %3d items', $source->name, count($items)));

        return $items;
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

        $this->warn(sprintf('  %-22s FAILED (%s)', $source->name, $status));

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function parseFeed(string $body, object $source, array &$seenUrls, int $limit): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        // RSS puts items under channel; Atom uses top-level entries.
        $entries = $xml->channel->item ?? $xml->entry ?? [];

        $items = [];

        foreach ($entries as $entry) {
            if (count($items) >= $limit) {
                break;
            }

            $url = trim((string) ($entry->link['href'] ?? $entry->link ?? $entry->guid ?? ''));
            if ($url === '' || $this->isBlockedPath($url)) {
                continue;
            }

            $key = preg_replace('/[#?].*$/', '', $url);
            if (isset($seenUrls[$key])) {
                continue;
            }
            $seenUrls[$key] = true;

            $title = $this->cleanText((string) ($entry->title ?? ''));
            if ($title === '') {
                continue;
            }

            $published = (string) ($entry->pubDate ?? $entry->published ?? $entry->updated ?? '');

            // Aggregators name the publisher behind each headline. Credit the
            // publisher, not the aggregator, and remember the homepage so the
            // discovery command can go and find their own feed.
            $publisher = $this->publisherOf($entry);

            if ($publisher !== null) {
                $this->notePublisher($publisher, $source->name);
            }

            $credit = $publisher['name'] ?? $source->name;

            $items[] = [
                'title'         => mb_substr($title, 0, 500),
                'url'           => $url,
                'source'        => $credit,
                'source_label'  => $credit,
                'source_name'   => $credit,
                'source_domain' => parse_url($source->base_url ?? $url, PHP_URL_HOST),
                'published_at'  => $this->normalisePublishedAt($published),
                'summary'       => mb_substr(
                    $this->cleanText((string) ($entry->description ?? $entry->summary ?? '')),
                    0,
                    800
                ),
                // No category is guessed here. Classification belongs to the AI
                // enrichment stage, which validates against a controlled list.
            ];
        }

        return $items;
    }

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
     * Correct publication times that cannot be true.
     *
     * Harian Metro and Berita Harian stamp Malaysian local time but label the
     * offset +0000, which places every one of their stories eight hours in the
     * future - enough to sort them above genuine breaking news for ever, and to
     * render as "5 hours from now". Malay Mail, by contrast, labels +0800
     * correctly, so this cannot be applied per publisher by name; it has to be
     * judged per timestamp.
     *
     * A story cannot be published later than now. Where removing Malaysia's
     * eight-hour offset lands the timestamp in a plausible recent window, that
     * is what the publisher meant. Otherwise the arrival time is used.
     */
    private function normalisePublishedAt(string $raw): string
    {
        $now = time();
        $ts  = $raw !== '' ? strtotime($raw) : false;

        if ($ts === false) {
            return gmdate('c', $now);
        }

        if ($ts > $now + 900) {
            $corrected = $ts - (8 * 3600);
            $plausible = $corrected <= $now + 900 && $corrected > $now - (14 * 86400);
            $ts = $plausible ? $corrected : $now;
        }

        return gmdate('c', $ts);
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

        foreach (array_chunk($items, self::BATCH_SIZE) as $chunk) {
            try {
                $response = Http::withHeaders([
                    'Host'         => self::INGEST_HOST,
                    'Content-Type' => 'application/json',
                ])
                    ->timeout(90)
                    ->post(self::INGEST_URL, ['items' => array_values($chunk)]);

                if (!$response->successful()) {
                    $this->error('  batch rejected: HTTP ' . $response->status());
                    Log::error('Ingest batch rejected', [
                        'status' => $response->status(),
                        'body'   => mb_substr($response->body(), 0, 500),
                    ]);
                    continue;
                }

                foreach ((array) $response->json('results', []) as $result) {
                    if (!($result['success'] ?? false)) {
                        $rejected++;
                    } elseif ($result['duplicate'] ?? false) {
                        $duplicate++;
                    } else {
                        $created++;
                    }
                }

            } catch (\Throwable $e) {
                $this->error('  batch failed: ' . $e->getMessage());
                Log::error('Ingest batch failed', ['error' => $e->getMessage()]);
            }
        }

        $this->info("Done. new={$created} duplicate={$duplicate} rejected={$rejected}");

        return 0;
    }
}

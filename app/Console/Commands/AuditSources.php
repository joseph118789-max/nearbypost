<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ask every publisher the three questions that decide whether we can carry them.
 *
 *   May we?     robots.txt, and the prose above it that people forget to read
 *   Can we?     RSS, sitemap, WordPress API, the article page itself
 *   How much?   sections they publish against sections we can reach
 *
 * Each of those was previously found by accident: The Star's prohibition after
 * twelve hours of work on other things, BusinessToday's open API after writing
 * the publisher off, Malay Mail's feed being the only copy of its articles
 * after three months of storing headlines. One command, run per source, would
 * have found all three on the day they were added.
 *
 * ⛔ It reads. It does not change what a source is set to, and it does not
 * switch anything on. A judgement about a publisher who forbids us is the
 * owner's to make, and it should be made from the finding rather than from a
 * decision already taken on their behalf.
 *
 * Run: php artisan sources:audit
 *      php artisan sources:audit --id=12
 */
class AuditSources extends Command
{
    protected $signature = 'sources:audit
        {--id= : Audit one source}
        {--all : Include sources that are switched off}';

    protected $description = 'Record what each publisher permits, exposes, and costs us';

    private const UA = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    /** Prose that means "no automated reading", whatever the directives say. */
    private const PROHIBITION_PHRASES = [
        'data mine', 'datamine', 'scrape', 'text and data mining',
        'large language model', 'artificial intelligence', 'machine learning',
        'strictly prohibited', 'without prior written permission',
    ];

    /** Crawlers a publisher names when they mean "no AI". */
    private const AI_AGENTS = [
        'gptbot', 'chatgpt-user', 'ccbot', 'anthropic-ai', 'claudebot',
        'google-extended', 'bytespider', 'perplexitybot', 'applebot-extended',
    ];

    public function handle(): int
    {
        $query = DB::table('sources')->whereNull('parent_source_id');

        if ($this->option('id')) {
            $query->where('id', (int) $this->option('id'));
        } elseif (!$this->option('all')) {
            $query->where('is_active', true);
        }

        $sources = $query->orderBy('name')->get();

        $this->info('Auditing ' . $sources->count() . ' publishers.');

        foreach ($sources as $source) {
            $this->line('  ' . $source->name);
            $this->auditOne($source);
        }

        $this->newLine();
        $this->info('Done. Read it at /admin/brain/constraints');

        return 0;
    }

    private function auditOne(object $source): void
    {
        $base = $source->base_url ?: $source->rss_url ?: $source->index_url;

        if (!$base) {
            return;
        }

        $host = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);

        [$policy, $note, $contact] = $this->robots($host);
        $routes = $this->routes($source, $host);
        $sections = $this->sections($host, $source);

        $best = $this->bestRoute($routes);

        DB::table('sources')->where('id', $source->id)->update([
            'robots_policy'      => $policy,
            'robots_note'        => $note,
            'robots_checked_at'  => now(),
            'licence_contact'    => $contact,
            'routes'             => json_encode($routes),
            'best_route'         => $best,
            'sections_published' => $sections['published'],
            'sections_reachable' => $sections['reachable'],
            'coverage_pct'       => $sections['published']
                ? (int) round(($sections['reachable'] / $sections['published']) * 100)
                : null,
            'constraint_note'    => $this->constraint($policy, $routes, $sections),
            'workaround_note'    => $this->workaround($policy, $routes, $contact),
            'audited_at'         => now(),
        ]);
    }

    /** @return array{0: string, 1: ?string, 2: ?string} */
    private function robots(string $host): array
    {
        [$code, $body] = $this->get($host . '/robots.txt', 15);

        if ($code !== 200 || trim($body) === '') {
            return ['unknown', 'No robots.txt was served (HTTP ' . $code . ').', null];
        }

        $lower = mb_strtolower($body);

        // The prose above the directives is where a licensing position is
        // stated, and it is the part a directive parser never reads.
        $comments = implode(' ', array_map(
            fn ($l) => ltrim($l, "# \t"),
            array_filter(explode("\n", $body), fn ($l) => str_starts_with(trim($l), '#'))
        ));

        $hits = array_values(array_filter(
            self::PROHIBITION_PHRASES,
            fn ($p) => str_contains(mb_strtolower($comments), $p)
        ));

        $contact = null;

        if (preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $comments, $m)) {
            $contact = $m[0];
        }

        if (count($hits) >= 2) {
            return [
                'prohibited',
                trim(mb_substr(preg_replace('/\s+/', ' ', $comments), 0, 900)),
                $contact,
            ];
        }

        $blocked = array_values(array_filter(
            self::AI_AGENTS,
            fn ($a) => str_contains($lower, 'user-agent: ' . $a)
        ));

        if ($blocked !== []) {
            return [
                'ai_restricted',
                'Blocks these crawlers by name: ' . implode(', ', $blocked)
                    . '. General crawling is permitted, but the intent is plainly to refuse AI reading.',
                $contact,
            ];
        }

        return ['permitted', 'Nothing in robots.txt stands against reading this publisher.', $contact];
    }

    /** @return array<string, array{status: int|string, detail: string}> */
    private function routes(object $source, string $host): array
    {
        $routes = [];

        // 1. The feed we already have registered.
        if ($source->rss_url) {
            [$code, $body] = $this->get($source->rss_url, 20);
            $items = substr_count($body, '<item');
            $routes['rss'] = [
                'status' => $code,
                'detail' => $code === 200
                    ? $items . ' items, ' . $this->medianFeedText($body) . ' chars each'
                    : 'HTTP ' . $code,
            ];
        }

        // 2. A news sitemap: the authoritative list of what was published.
        foreach (['/sitemap.xml', '/news-sitemap.xml', '/sitemap_index.xml'] as $path) {
            [$code, $body] = $this->get($host . $path, 20);

            if ($code === 200 && str_contains($body, '<loc>')) {
                $isIndex = str_contains($body, '<sitemapindex');
                $routes['sitemap'] = [
                    'status' => 200,
                    'detail' => $isIndex
                        ? 'index of ' . substr_count($body, '<loc>') . ' sub-sitemaps'
                        : substr_count($body, '<loc>') . ' URLs listed',
                ];
                break;
            }
        }

        // 3. A WordPress API, which some publishers leave open while blocking
        //    their own article pages.
        [$code, $body] = $this->get($host . '/wp-json/wp/v2/posts?per_page=1', 15);

        if ($code === 200 && str_contains($body, 'content')) {
            $routes['wp_json'] = ['status' => 200, 'detail' => 'open, carries article text'];
        } elseif ($code !== 404) {
            $routes['wp_json'] = ['status' => $code, 'detail' => 'HTTP ' . $code];
        }

        // 4. An article page, which is what everything else falls back to.
        $sample = DB::table('news_items')
            ->where('source', $source->name)
            ->whereNotNull('url')
            ->where('url', 'not like', '%news.google%')
            ->orderByDesc('id')
            ->value('url');

        if ($sample) {
            [$code, $body] = $this->get($sample, 20);
            $routes['article_page'] = [
                'status' => $code,
                'detail' => $code === 200 ? strlen($body) . ' bytes returned' : 'HTTP ' . $code,
            ];
        }

        return $routes;
    }

    /**
     * Sections they publish, against sections anything we can read reaches.
     *
     * @return array{published: ?int, reachable: ?int}
     */
    private function sections(string $host, object $source): array
    {
        $published = null;
        $reachable = null;

        [$code, $map] = $this->get($host . '/sitemap.xml', 20);

        if ($code === 200) {
            preg_match_all('#<loc>' . preg_quote($host, '#') . '/([^<]+)</loc>#', $map, $m);
            $paths = array_map(fn ($p) => implode('/', array_slice(explode('/', trim($p, '/')), 0, 2)), $m[1] ?? []);
            $published = count(array_unique(array_filter($paths))) ?: null;
        }

        if ($source->rss_url) {
            [$code, $feed] = $this->get($source->rss_url, 20);

            if ($code === 200) {
                preg_match_all('#<link>' . preg_quote($host, '#') . '/([^<]+)</link>#', $feed, $m);
                $paths = array_map(fn ($p) => implode('/', array_slice(explode('/', trim($p, '/')), 0, 2)), $m[1] ?? []);
                $reachable = count(array_unique(array_filter($paths))) ?: null;
            }
        }

        return ['published' => $published, 'reachable' => $reachable];
    }

    private function bestRoute(array $routes): ?string
    {
        // ⛔ Counted, not matched. This read !str_contains(detail, '0 items'),
        // and "50 items" contains "0 items" - so every publisher with a round
        // number of items was reported as unreachable, Malay Mail among them.
        if (($routes['rss']['status'] ?? null) === 200
            && (int) ($routes['rss']['detail'] ?? 0) > 0) {
            return 'rss';
        }

        if (($routes['wp_json']['status'] ?? null) === 200) {
            return 'wp_json';
        }

        if (($routes['article_page']['status'] ?? null) === 200) {
            return 'article_page';
        }

        if (($routes['sitemap']['status'] ?? null) === 200) {
            return 'sitemap';
        }

        return null;
    }

    private function constraint(string $policy, array $routes, array $sections): string
    {
        $parts = [];

        if ($policy === 'prohibited') {
            $parts[] = 'They forbid automated extraction in writing, whatever is technically reachable.';
        } elseif ($policy === 'ai_restricted') {
            $parts[] = 'They block AI crawlers by name. General reading is permitted, but the intent is clear.';
        }

        if (($routes['rss']['status'] ?? null) !== 200) {
            $parts[] = isset($routes['rss'])
                ? 'No working RSS feed.'
                : 'No RSS feed registered.';
        }

        if (($routes['article_page']['status'] ?? 200) !== 200) {
            $parts[] = 'Article pages answer ' . $routes['article_page']['status'] . ', so the feed is the only copy we can hold.';
        }

        if ($sections['published'] && $sections['reachable'] && $sections['reachable'] < $sections['published'] * 0.6) {
            $parts[] = 'The feed reaches ' . $sections['reachable'] . ' of about '
                . $sections['published'] . ' sections they publish.';
        }

        return $parts === [] ? 'None found. Everything they publish is reachable.' : implode(' ', $parts);
    }

    private function workaround(string $policy, array $routes, ?string $contact): string
    {
        if ($policy === 'prohibited') {
            return 'Written permission is the only route. '
                . ($contact ? 'They give a licensing contact: ' . $contact . '. ' : '')
                . 'Until then the same events are usually available from other outlets - check the overlap before assuming a story is lost.';
        }

        if (($routes['rss']['status'] ?? null) === 200 && ($routes['article_page']['status'] ?? null) !== 200) {
            return 'Read the feed and do not fetch the page: set the strategy to feed_only.';
        }

        if (($routes['wp_json']['status'] ?? null) === 200 && ($routes['article_page']['status'] ?? null) !== 200) {
            return 'Their article pages refuse us but their WordPress API does not: set the strategy to wp_json.';
        }

        if (($routes['rss']['status'] ?? null) !== 200 && ($routes['sitemap']['status'] ?? null) === 200) {
            return 'No feed, but they publish a sitemap. Reading the sitemap for new URLs is the route, and it needs building.';
        }

        if (($routes['article_page']['status'] ?? null) === 200) {
            return 'The feed and the article page both work. Nothing needed.';
        }

        return 'No route found. Worth a person opening the site to see what is actually there.';
    }

    private function medianFeedText(string $xml): int
    {
        preg_match_all('#<content:encoded>(.*?)</content:encoded>#s', $xml, $m);
        $blocks = $m[1];

        if ($blocks === []) {
            preg_match_all('#<description>(.*?)</description>#s', $xml, $m);
            $blocks = $m[1];
        }

        if ($blocks === []) {
            return 0;
        }

        $lengths = array_map(
            fn ($b) => mb_strlen(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(html_entity_decode($b)))))),
            $blocks
        );

        sort($lengths);

        return $lengths[intdiv(count($lengths), 2)];
    }

    /** @return array{0: int, 1: string} */
    private function get(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Their crawl-delay is a request, and one second between publishers
        // costs an audit nothing.
        usleep(700000);

        return [$code, $body];
    }
}

<?php

namespace App\Services\Ingest;

use Illuminate\Support\Facades\Http;

/**
 * Ask a site how it would like to be read.
 *
 * Everything this does was done by hand on 5 Sep 2026 while adding ten event
 * and mall sources, and the same four questions came up every time: may we
 * read it, is there a feed, is there an API, and how often does it actually
 * publish. Doing it by hand also produced three wrong answers - two sites
 * declared unusable that were fine, and one dismissed because a link count
 * found nothing while the events sat in the page as JSON. A probe that always
 * asks the same questions in the same order does not get bored on the third
 * site.
 *
 * ⛔ IT READS AND REPORTS. It never adds a source, never switches anything on
 * and never follows instructions found in a page. The output is evidence for a
 * person.
 */
class SourceProbe
{
    private const UA = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    /** @return array<string, mixed> */
    public function probe(string $url): array
    {
        $url  = $this->normalise($url);
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === '') {
            return ['ok' => false, 'why' => 'That does not look like a web address.'];
        }

        // ⛔⛔ THIS URL CAME FROM A PUBLIC FORM. Without this check anybody
        // could make the server fetch anything it can reach - the Nominatim
        // containers on 127.0.0.1:8090 and :8091, the Docker ports, the
        // database, or a cloud provider's metadata service on 169.254.169.254,
        // which is where instance credentials live. That is SSRF, and it is the
        // whole reason a "just fetch what they typed" feature is dangerous.
        $refusal = $this->refuseIfInternal($host);

        if ($refusal !== null) {
            return ['ok' => false, 'why' => $refusal, 'url' => $url, 'host' => $host];
        }

        $out = [
            'url'     => $url,
            'host'    => $host,
            'checked' => now()->toDateTimeString(),
            'robots'  => $this->robots($host),
            'routes'  => [],
        ];

        // Cheapest and best first: a feed, then an events API, then a listing
        // page. The order is the order of how much can go wrong later.
        foreach ($this->candidates($url, $host) as $label => $candidate) {
            $found = $this->try($candidate['url'], $candidate['kind']);

            if ($found !== null) {
                $out['routes'][$label] = $found;
            }
        }

        $best = $this->best($out['routes']);

        $out['found_kind'] = $best['kind'] ?? null;
        $out['found_url']  = $best['url'] ?? null;
        $out['items_per_week'] = $best['per_week'] ?? null;
        $out['ok'] = $best !== null;

        return $out;
    }

    /**
     * How often the site publishes, from its own dates.
     *
     * ⭐ MEASURED, NOT ASKED AND NOT GUESSED. The owner's rule is that weekly is
     * the default and a genuinely active news site earns daily. "Active" is a
     * number, and this is the number.
     */
    public function cadenceFor(?float $perWeek): string
    {
        // Five a week is roughly one a working day. Below that, a weekly sweep
        // sees everything anyway and a daily one buys the same page over again.
        return ($perWeek ?? 0) >= 5.0 ? 'daily' : 'weekly';
    }

    /* --------------------------------------------------------------- innards */

    /**
     * Refuse anything that is not a real, public website.
     *
     * ⛔ THE NAME IS RESOLVED, NOT PATTERN-MATCHED. "localhost" is easy to
     * block and useless on its own: a name somebody controls can resolve to
     * 127.0.0.1, and a URL can carry a decimal or hex address. So every address
     * the host resolves to is checked, and one bad answer refuses the lot.
     */
    public function refuseIfInternal(string $host): ?string
    {
        $scheme = 'https';

        if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return 'That address has characters we do not accept.';
        }

        // A bare IP is never a publisher's website.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return "Please give the name of the site rather than a numeric address.";
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $ips     = [];

        foreach ($records ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }

        $ips = array_values(array_filter($ips));

        if ($ips === []) {
            return 'We could not find that site - check the address.';
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That address points inside a private network, so we cannot read it.';
            }
        }

        return null;
    }

    /** @return array<string, array{url: string, kind: string}> */
    private function candidates(string $url, string $host): array
    {
        $root = 'https://' . $host;

        return [
            'given page'       => ['url' => $url,  'kind' => 'page'],
            'rss /feed/'       => ['url' => $root . '/feed/', 'kind' => 'rss'],
            'rss /rss'         => ['url' => $root . '/rss', 'kind' => 'rss'],
            'rss /feed.xml'    => ['url' => $root . '/feed.xml', 'kind' => 'rss'],
            'wordpress posts'  => ['url' => $root . '/wp-json/wp/v2/posts?per_page=20', 'kind' => 'wp_posts'],
            'events calendar'  => ['url' => $root . '/wp-json/tribe/events/v1/events?per_page=50', 'kind' => 'events_api'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function try(string $url, string $kind): ?array
    {
        try {
            // ⛔ Redirects are where an SSRF check gets bypassed: a public
            // host answering 302 to http://169.254.169.254 walks straight
            // past a front-door check. Each hop is re-checked.
            $r = Http::withHeaders(['User-Agent' => self::UA])
                ->withOptions(['allow_redirects' => [
                    'max'             => 3,
                    'strict'          => true,
                    'referer'         => false,
                    'protocols'       => ['http', 'https'],
                    'on_redirect'     => function ($request, $response, $uri) {
                        if ($this->refuseIfInternal((string) $uri->getHost()) !== null) {
                            throw new \RuntimeException('redirect into a private network');
                        }
                    },
                ]])
                ->timeout(20)->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        if (!$r->successful()) {
            return ['url' => $url, 'kind' => $kind, 'status' => $r->status(), 'usable' => false];
        }

        $body = $r->body();

        return match ($kind) {
            'rss'        => $this->readFeed($url, $body),
            'events_api' => $this->readEventsApi($url, $body),
            'wp_posts'   => $this->readWpPosts($url, $body),
            default      => $this->readPage($url, $body),
        };
    }

    /** @return array<string, mixed>|null */
    private function readFeed(string $url, string $body): ?array
    {
        if (!str_contains($body, '<item') && !str_contains($body, '<entry')) {
            return null;
        }

        preg_match_all('#<(?:pubDate|published|updated)>([^<]+)<#i', $body, $m);

        $count = max(substr_count($body, '<item'), substr_count($body, '<entry'));

        return [
            'url' => $url, 'kind' => 'rss', 'usable' => true, 'items' => $count,
            'per_week' => $this->perWeek($m[1] ?? []),
            'newest'   => $this->newest($m[1] ?? []),
        ];
    }

    /** @return array<string, mixed>|null */
    private function readEventsApi(string $url, string $body): ?array
    {
        $d = json_decode($body, true);

        if (!is_array($d) || !isset($d['events']) || $d['events'] === []) {
            return null;
        }

        return [
            'url' => $url, 'kind' => 'events_api', 'usable' => true,
            'items' => (int) ($d['total'] ?? count($d['events'])),
            'pages' => (int) ($d['total_pages'] ?? 1),
            // An events calendar is a calendar, not a newsroom. Cadence is
            // about how often we should LOOK, and once a week is plenty.
            'per_week' => 0.0,
        ];
    }

    /** @return array<string, mixed>|null */
    private function readWpPosts(string $url, string $body): ?array
    {
        $d = json_decode($body, true);

        if (!is_array($d) || $d === [] || !isset($d[0]['date'])) {
            return null;
        }

        return [
            'url' => $url, 'kind' => 'wp_posts', 'usable' => true, 'items' => count($d),
            'per_week' => $this->perWeek(array_column($d, 'date')),
            'newest'   => $this->newest(array_column($d, 'date')),
        ];
    }

    /**
     * A page with no feed: is there anything in it that looks like a list?
     *
     * ⛔ IT DOES NOT ONLY COUNT LINKS. Counting links is exactly how KLCC's
     * calendar was declared empty when twelve events were sitting in the markup
     * as JSON, and how Mid Valley's board was missed when every listing was
     * there as plain text. All three shapes are looked for.
     */
    private function readPage(string $url, string $body): ?array
    {
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;

        $host  = parse_url($url, PHP_URL_HOST);
        $links = 0;

        if (preg_match_all('#<a\b[^>]*href="([^"]+)"#i', $clean, $m)) {
            $paths = [];

            foreach ($m[1] as $href) {
                $h = parse_url($href, PHP_URL_HOST);

                if ($h === null || $h === $host) {
                    $paths[preg_replace('#/[^/]*$#', '', (string) parse_url($href, PHP_URL_PATH))] = true;
                }
            }

            $links = count($paths);
        }

        return [
            'url' => $url, 'kind' => 'page', 'usable' => false,
            'same_site_link_groups' => $links,
            'embedded_json_list'    => (bool) preg_match('/"pages"\s*:\s*\{"0"/', str_replace('\\"', '"', $body)),
            'text_date_labels'      => preg_match_all('/(^|>)\s*Date:?\s*(<|$)/mi', $clean),
            'note' => 'A page with no feed still often carries its list - as embedded JSON, or as plain text. Worth a person looking.',
        ];
    }

    /** @param list<string> $dates */
    private function perWeek(array $dates): ?float
    {
        $times = [];

        foreach ($dates as $d) {
            $t = strtotime((string) $d);

            if ($t !== false && $t > strtotime('-120 days') && $t <= time() + 86400) {
                $times[] = $t;
            }
        }

        if (count($times) < 2) {
            return null;
        }

        $span = (max($times) - min($times)) / 86400;

        return $span < 1 ? (float) count($times) * 7 : round(count($times) / $span * 7, 2);
    }

    /** @param list<string> $dates */
    private function newest(array $dates): ?string
    {
        $times = array_filter(array_map(fn ($d) => strtotime((string) $d), $dates));

        return $times === [] ? null : date('Y-m-d', max($times));
    }

    /**
     * @param  array<string, array<string, mixed>>  $routes
     * @return array<string, mixed>|null
     */
    private function best(array $routes): ?array
    {
        foreach (['events_api', 'rss', 'wp_posts'] as $kind) {
            foreach ($routes as $route) {
                if (($route['kind'] ?? '') === $kind && ($route['usable'] ?? false)) {
                    return $route;
                }
            }
        }

        return null;
    }

    /**
     * What the site says about being read. Three signals, not one - path
     * disallows, named-crawler blocks, and Cloudflare's content signals.
     *
     * @return array<string, mixed>
     */
    private function robots(string $host): array
    {
        try {
            $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(15)
                ->get('https://' . $host . '/robots.txt');
        } catch (\Throwable $e) {
            return ['reachable' => false];
        }

        if (!$r->successful()) {
            return ['reachable' => false, 'status' => $r->status()];
        }

        $txt = $r->body();

        // A challenge page answers 200 with HTML, which is not a robots file.
        if (str_contains($txt, '<html') || str_contains($txt, 'Just a moment')) {
            return ['reachable' => false, 'note' => 'Answered with a page, not a robots file - usually a bot challenge.'];
        }

        preg_match('/Content-Signal:\s*([^\r\n]+)/i', $txt, $cs);

        return [
            'reachable'       => true,
            'content_signal'  => $cs[1] ?? null,
            'disallows_all'   => (bool) preg_match('/User-agent:\s*\*\s*[\r\n]+Disallow:\s*\/\s*$/mi', $txt),
            'blocks_ai_bots'  => (bool) preg_match('/User-agent:\s*(GPTBot|CCBot|ClaudeBot|Google-Extended)/i', $txt),
        ];
    }

    private function normalise(string $url): string
    {
        $url = trim($url);

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }
}

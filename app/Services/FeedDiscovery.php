<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Find the feeds a publisher offers.
 *
 * Given a homepage, this answers two questions: does this site publish a feed,
 * and does it publish per-section feeds. The second is what fills sub-categories
 * that general news feeds never reach - a national front page carries almost no
 * badminton, but a sports section feed carries little else.
 *
 * Two ways of looking, cheapest first:
 *   1. Feed autodiscovery - the <link rel="alternate" type="application/rss+xml">
 *      tags a site declares in its own <head>. Authoritative when present.
 *   2. Conventional paths - /feed, /rss, /feed/rss and section variants, for the
 *      many sites that publish a feed without ever declaring it.
 *
 * Everything found is validated by fetching and parsing it. A URL that returns
 * 200 but no items is not a feed.
 */
class FeedDiscovery
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    /** Minimum items before we believe a URL is a live feed. */
    private const MIN_ITEMS = 3;

    /** Paths worth trying when a site declares nothing. */
    private const COMMON_PATHS = ['/feed', '/feed/', '/rss', '/rss.xml', '/feed/rss', '/index.xml', '/atom.xml'];

    /**
     * Sections worth their own feed, mapped to the sub-categories they feed.
     * Ordered so the most specific are tried first.
     */
    public const SECTIONS = [
        'sports'        => ['/sports/feed', '/sport/feed', '/rss/sport', '/sports/rss', '/category/sports/feed', '/sports'],
        'business'      => ['/business/feed', '/rss/business', '/business/rss', '/category/business/feed'],
        'technology'    => ['/tech/feed', '/technology/feed', '/rss/tech', '/category/technology/feed'],
        'lifestyle'     => ['/lifestyle/feed', '/rss/lifestyle', '/category/lifestyle/feed'],
        'entertainment' => ['/entertainment/feed', '/rss/entertainment', '/showbiz/feed'],
        'property'      => ['/property/feed', '/rss/property', '/category/property/feed'],
        'automotive'    => ['/automotive/feed', '/cars/feed', '/motoring/feed'],
        'health'        => ['/health/feed', '/rss/health', '/category/health/feed'],
        'world'         => ['/world/feed', '/rss/world'],
    ];

    /**
     * Discover the main feed for a site.
     * Returns ['url' => string, 'items' => int, 'method' => string] or null.
     */
    public function discoverMain(string $homepage): ?array
    {
        foreach ($this->declaredFeeds($homepage) as $candidate) {
            if ($result = $this->validate($candidate)) {
                $result['method'] = 'autodiscovery';
                return $result;
            }
        }

        foreach (self::COMMON_PATHS as $path) {
            if ($result = $this->validate($this->join($homepage, $path))) {
                $result['method'] = 'common-path';
                return $result;
            }
        }

        return null;
    }

    /**
     * Discover section feeds for a site.
     * Returns [section => ['url' => ..., 'items' => ..., 'method' => ...]].
     */
    public function discoverSections(string $homepage, array $only = []): array
    {
        $found = [];

        foreach (self::SECTIONS as $section => $paths) {
            if ($only !== [] && !in_array($section, $only, true)) {
                continue;
            }

            foreach ($paths as $path) {
                if ($result = $this->validate($this->join($homepage, $path))) {
                    $result['method'] = 'section-path';
                    $found[$section] = $result;
                    break;
                }
            }
        }

        return $found;
    }

    /** Feed URLs a page declares in its own head. */
    public function declaredFeeds(string $homepage): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(20)
                ->get($homepage);

            if (!$response->successful()) {
                return [];
            }

            $html = $response->body();

        } catch (\Throwable $e) {
            Log::info('Feed autodiscovery fetch failed', ['url' => $homepage, 'error' => $e->getMessage()]);
            return [];
        }

        // <link rel="alternate" type="application/rss+xml" href="...">
        preg_match_all('#<link[^>]+>#i', $html, $tags);

        $out = [];

        foreach ($tags[0] ?? [] as $tag) {
            if (!preg_match('#type=["\']application/(rss|atom)\+xml["\']#i', $tag)) {
                continue;
            }

            if (!preg_match('#href=["\']([^"\']+)["\']#i', $tag, $href)) {
                continue;
            }

            $url = html_entity_decode($href[1], ENT_QUOTES, 'UTF-8');
            $out[] = str_starts_with($url, 'http') ? $url : $this->join($homepage, $url);
        }

        return array_values(array_unique($out));
    }

    /**
     * Fetch a candidate and decide whether it is a usable feed.
     * Returns ['url' => string, 'items' => int, 'title' => ?string] or null.
     */
    public function validate(string $url): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(20)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();

            // Cheap rejection before paying for an XML parse.
            if (!str_contains($body, '<item') && !str_contains($body, '<entry')) {
                return null;
            }

            $previous = libxml_use_internal_errors(true);
            $xml      = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($xml === false) {
                return null;
            }

            $entries = $xml->channel->item ?? $xml->entry ?? [];
            $count   = count($entries);

            if ($count < self::MIN_ITEMS) {
                return null;
            }

            return [
                'url'   => (string) $response->effectiveUri(),
                'items' => $count,
                'title' => trim((string) ($xml->channel->title ?? $xml->title ?? '')) ?: null,
            ];

        } catch (\Throwable $e) {
            return null;
        }
    }

    private function join(string $base, string $path): string
    {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

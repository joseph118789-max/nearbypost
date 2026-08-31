<?php

namespace App\Services;

use App\Services\Classification\ContentPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read article links off a publisher's section page.
 *
 * Feeds are convenient but they are a publisher's choice, not a guarantee. The
 * Star and Bernama - two of the largest outlets in the country - both advertise
 * feeds that answer 404, so relying on RSS alone meant getting nothing from
 * either. Their section pages work fine.
 *
 * This deliberately reads the index and stops there. It does not follow links
 * out of an article, and it does not crawl a site recursively: it takes the
 * headlines a section page is already showing and hands the URLs to the same
 * pipeline a feed item goes through, where extraction fetches the body and
 * enrichment classifies it.
 *
 * Each source carries its own link_pattern, because "what an article URL looks
 * like here" is knowledge about that site - The Star dates its paths, Bernama
 * uses news.php?id=. Keeping it on the source row means it is recorded rather
 * than rediscovered.
 */
class IndexCrawler
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    /** A headline shorter than this is a navigation label, not a story. */
    private const MIN_TITLE_LENGTH = 20;

    private const MAX_LINKS = 60;

    /** news_items.title is varchar(255); trimming to more is not a cap. */
    private const MAX_TITLE_LENGTH = 250;

    public function __construct(private ?ContentPolicy $policy = null)
    {
        $this->policy = $policy ?: new ContentPolicy();
    }

    /**
     * Article links from a section page.
     *
     * @return list<array{url: string, title: string}>
     */
    public function crawl(string $indexUrl, ?string $linkPattern = null): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent'      => self::USER_AGENT,
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-MY,en;q=0.9,ms;q=0.8',
            ])
                ->timeout(25)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($indexUrl);

            if (!$response->successful()) {
                return [];
            }

            $html = $response->body();

        } catch (\Throwable $e) {
            Log::info('Index crawl failed', ['url' => $indexUrl, 'error' => $e->getMessage()]);

            return [];
        }

        return $this->extractLinks($html, $indexUrl, $linkPattern);
    }

    /** @return list<array{url: string, title: string}> */
    public function extractLinks(string $html, string $indexUrl, ?string $linkPattern): array
    {
        // Remove anything that is not page text before looking for links.
        // Without this the regex matches inside <script>, and a template call
        // like GenerateMediaTagV2({...}) is captured as a headline.
        $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // <a href="...">headline</a>, capturing the text so the item has a
        // title before extraction has run.
        preg_match_all(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $seen  = [];
        $items = [];

        foreach ($matches as $match) {
            if (count($items) >= self::MAX_LINKS) {
                break;
            }

            $href  = html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
            $title = $this->cleanText($match[2]);

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $url = $this->absolute($href, $indexUrl);

            if ($url === null || !$this->sameSite($url, $indexUrl)) {
                continue;
            }

            if ($linkPattern !== null && $linkPattern !== '' && !@preg_match($linkPattern, $url)) {
                continue;
            }

            $key = preg_replace('/[#?].*$/', '', mb_strtolower($url));

            if (isset($seen[$key])) {
                continue;
            }

            // Bernama and others repeat the same story as an image link with no
            // text; the text-bearing copy is the one worth keeping.
            if (mb_strlen($title) < self::MIN_TITLE_LENGTH) {
                continue;
            }

            if ($this->looksLikeCode($title)) {
                continue;
            }

            if ($this->policy->isListingPage($title)) {
                continue;
            }

            $seen[$key] = true;

            $items[] = ['url' => $url, 'title' => mb_substr($title, 0, self::MAX_TITLE_LENGTH)];
        }

        return $items;
    }

    /**
     * Does this "headline" look like source code?
     *
     * A last line of defence for markup the tag stripper leaves behind -
     * inline handlers, JSON blobs, templating calls. No real headline contains
     * a function call or a brace.
     */
    private function looksLikeCode(string $text): bool
    {
        if (preg_match('/[{}]|\(\{|=>|function\s*\(|\bvar\s|\blet\s|;\s*$/u', $text)) {
            return true;
        }

        // A run of characters with no spaces is an identifier, not a sentence.
        return (bool) preg_match('/\S{45,}/u', $text);
    }

    /** Resolve a possibly-relative href against the page it came from. */
    private function absolute(string $href, string $base): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $parts = parse_url($base);

        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $root = $parts['scheme'] . '://' . $parts['host'];

        if (str_starts_with($href, '//')) {
            return $parts['scheme'] . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $root . $href;
        }

        // Relative to the index page's directory, which is how Bernama links.
        $path = $parts['path'] ?? '/';
        $dir  = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/');

        return $root . $dir . '/' . $href;
    }

    /**
     * Stay on the publisher's own site.
     *
     * A section page links out to social networks, app stores and syndication
     * partners. Following those would be crawling the web, which is not what
     * this is for.
     */
    private function sameSite(string $url, string $base): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $home = parse_url($base, PHP_URL_HOST);

        if ($host === null || $home === null) {
            return false;
        }

        $strip = fn (string $h) => preg_replace('/^www\./', '', mb_strtolower($h));

        return $strip($host) === $strip($home);
    }

    private function cleanText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text ?? '');

        return trim($text ?? '');
    }
}

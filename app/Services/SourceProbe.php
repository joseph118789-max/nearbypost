<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Read a URL the way the crawler would, and say what happened.
 *
 * FeedDiscovery::validate() answers this question with null for every kind of
 * failure, which is enough for a machine deciding whether to keep a candidate
 * and useless to a person deciding what to fix. "Not a feed", "403 to our
 * crawler" and "a feed with nothing in it" need three different responses, and
 * a single null tells an editor to try again.
 *
 * So this reports the status, what came back, and what it managed to read - and
 * where it can, it names the likely cause, because "403 - this publisher
 * refuses identified crawlers" is the sentence that saves the next hour.
 */
class SourceProbe
{
    private const TIMEOUT = 20;
    private const USER_AGENT = 'NearbypostBot/1.0 (+https://nearbypost.com)';

    /**
     * @return array{
     *     ok: bool, status: ?int, kind: string, items: int,
     *     title: ?string, samples: list<string>, message: string
     * }
     */
    public function probe(string $url, string $expectedKind = 'rss'): array
    {
        $url = trim($url);

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->fail(null, 'That is not a URL the crawler can read.');
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TIMEOUT)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);
        } catch (\Throwable $e) {
            return $this->fail(null, 'Could not connect: ' . mb_substr($e->getMessage(), 0, 160));
        }

        $status = $response->status();

        if (!$response->successful()) {
            return $this->fail($status, $this->explainStatus($status));
        }

        $body = $response->body();

        if (trim($body) === '') {
            return $this->fail($status, 'Answered, but sent nothing back.');
        }

        $feed = $this->readFeed($body);

        if ($feed !== null) {
            if ($feed['items'] === 0) {
                return [
                    'ok' => false, 'status' => $status, 'kind' => 'rss', 'items' => 0,
                    'title' => $feed['title'], 'samples' => [],
                    'message' => 'A valid feed, but it is empty right now. Worth retrying before writing it off.',
                ];
            }

            return [
                'ok' => true, 'status' => $status, 'kind' => 'rss',
                'items' => $feed['items'], 'title' => $feed['title'],
                'samples' => $feed['samples'],
                'message' => 'Read it as a feed: ' . $feed['items'] . ' item(s).',
            ];
        }

        // Not a feed. It may still be a section page the index crawler can read,
        // which is a different and equally usable kind of source.
        $links = $this->countArticleLinks($body, $url);

        if ($links >= 5) {
            return [
                'ok' => true, 'status' => $status, 'kind' => 'index', 'items' => $links,
                'title' => $this->htmlTitle($body), 'samples' => [],
                'message' => $expectedKind === 'rss'
                    ? 'Not a feed, but it looks like a section page with ' . $links . ' article links. Set the kind to "index" to use it.'
                    : 'Read it as a section page: ' . $links . ' article link(s).',
            ];
        }

        return $this->fail($status,
            'Answered, but it is neither a feed nor a page with article links on it. '
            . 'Check the address points at the feed rather than the homepage.');
    }

    /** @return array{items: int, title: ?string, samples: list<string>}|null */
    private function readFeed(string $body): ?array
    {
        if (!str_contains($body, '<item') && !str_contains($body, '<entry')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        $entries = $xml->channel->item ?? $xml->entry ?? [];
        $samples = [];

        foreach ($entries as $entry) {
            $title = trim((string) ($entry->title ?? ''));

            if ($title !== '') {
                $samples[] = mb_substr($title, 0, 110);
            }

            if (count($samples) >= 3) {
                break;
            }
        }

        return [
            'items'   => count($entries),
            'title'   => trim((string) ($xml->channel->title ?? $xml->title ?? '')) ?: null,
            'samples' => $samples,
        ];
    }

    /**
     * Roughly how many links on this page look like articles.
     *
     * Deliberately crude - this only has to distinguish "a page of stories"
     * from "a 404 page" well enough for an editor to decide.
     */
    private function countArticleLinks(string $body, string $url): int
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        $body = preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $body) ?? $body;

        if (!preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\']#i', $body, $matches)) {
            return 0;
        }

        $seen = [];

        foreach ($matches[1] as $href) {
            $path = parse_url($href, PHP_URL_PATH) ?: '';
            $linkHost = parse_url($href, PHP_URL_HOST);

            if ($linkHost !== null && $host !== '' && !str_contains($linkHost, $host)) {
                continue;
            }

            // A dated path or a long hyphenated slug: the shape of an article.
            if (preg_match('#/\d{4}/\d{1,2}/#', $path)
                || preg_match('#/[a-z0-9]+(?:-[a-z0-9]+){3,}#i', $path)) {
                $seen[$path] = true;
            }
        }

        return count($seen);
    }

    private function htmlTitle(string $body): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)
            ? mb_substr(trim(html_entity_decode($m[1])), 0, 120)
            : null;
    }

    /** Name the likely cause, where the status makes it obvious. */
    private function explainStatus(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 =>
                $status . ' - this publisher refuses our crawler. Nothing here can fix that; it is a decision on their side.',
            $status === 404 =>
                '404 - nothing at that address. The feed has probably moved.',
            $status === 429 =>
                '429 - we are asking too often. Slow this source down before trying again.',
            $status >= 500 =>
                $status . ' - the publisher\'s own server is failing. Worth retrying later.',
            default => 'Answered ' . $status . ', which the crawler treats as a failure.',
        };
    }

    private function fail(?int $status, string $message): array
    {
        return [
            'ok' => false, 'status' => $status, 'kind' => 'unknown',
            'items' => 0, 'title' => null, 'samples' => [], 'message' => $message,
        ];
    }
}

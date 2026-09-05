<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\ExtractionJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ExtractArticleContent extends Command
{
    protected $signature = 'ingest:extract {--news_item_id=} {--redate= : days back: re-read the page for the publication time of stories that carry a scraped time (or --precision=date for day-only ones)} {--precision=scraped} {--limit=300}';
    protected $description = 'Extract content from news items';

    /** Manual V6 section 9: keep roughly the first 500 words. */
    /**
     * The article is kept whole.
     *
     * This was 500 words, defended on the inverted pyramid: the facts are at
     * the top. True of who and what; false of where. A village, a district or a
     * road name usually appears in the third or fourth paragraph, so the cap
     * removed exactly the part that answers the question this site exists to
     * ask, and locations came back as states.
     *
     * The ceiling that remains is a guard against a page that is not an article
     * at all - a forum thread, a transcript - not an editorial judgement about
     * what matters.
     */
    private const MAX_WORDS = 4000;

    /** A page that has not answered in this long is not worth waiting for. */
    private const EXTRACT_TIMEOUT_SECONDS = 25;

    /** Below this, whatever came back is not an article body. */
    private const MIN_EXTRACT_CHARS = 200;

    /** The same voice the RSS fetcher uses, which these sites answer. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    /**
     * Below this, we are holding a headline, not an article.
     *
     * A summary written from a title is a paraphrase of the title, and a
     * location inferred from one is a guess. Six hundred characters is about
     * three sentences: enough to say what happened and where, which is all the
     * classifier is being asked.
     */
    private const MIN_USABLE_CHARS = 600;

    /**
     * The same demand, in a script where each character carries more.
     *
     * Not a guess at a ratio: 250 sits in the measured gap between China
     * Press's boilerplate (145 characters of related-news links and comment
     * policy) and its shortest real article (302 characters reporting a drone
     * strike with a death toll).
     */
    private const MIN_USABLE_CHARS_CJK = 250;

    /**
     * How much text is enough, judged by the writing system in front of us.
     *
     * Measured from the text rather than taken from the source's declared
     * language: a Chinese paper runs English wire copy, an English one quotes
     * Chinese, and the story in hand is the only thing that knows which it is.
     */
    private function minUsableChars(?string $text): int
    {
        $text = (string) $text;
        $length = mb_strlen($text);

        if ($length === 0) {
            return self::MIN_USABLE_CHARS;
        }

        preg_match_all('/\p{Han}/u', $text, $han);

        // A third is enough to say the piece is written in Han rather than
        // merely quoting a name in it.
        return (count($han[0]) / $length) >= 0.30
            ? self::MIN_USABLE_CHARS_CJK
            : self::MIN_USABLE_CHARS;
    }

    public function handle()
    {
        if ($this->option('redate')) {
            return $this->redate((int) $this->option('redate'), (string) $this->option('precision'), (int) $this->option('limit'));
        }

        $newsItemId = $this->option('news_item_id');

        $query = NewsItem::whereDoesntHave('extractionJob', function ($q) {
            $q->whereIn('extraction_status', ['success', 'fallback_used']);
        });

        // ⛔ Stop after two failures. Some links can never yield an article -
        // an aggregator's wrapper URL answers 200 with a JavaScript page, so
        // there is nothing behind it however often it is asked - and without a
        // cap those spend every run forever, ahead of stories that would have
        // worked. Two attempts covers a site that was briefly down.
        $query->whereRaw(
            '(select count(*) from extraction_jobs j
                where j.news_item_id = news_items.id
                  and j.extraction_status = ?) < 2',
            ['failed']
        );

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        }

        // Newest first. The feed serves recent stories, so extracting a
        // two-month-old article before today's is work nobody reads.
        $items = $query->whereNotNull('url')
            ->orderByDesc('published_at')
            ->limit(100)
            ->get();

        $this->info("Processing {$items->count()} items.");

        foreach ($items as $item) {
            $this->processItem($item);
        }
    }

    /**
     * The publication time, read again from the page, for stories that were
     * stamped with the moment we fetched them. Text is not touched: only the
     * time, and only upward (a clock beats a day beats our own clock).
     */
    private function redate(int $days, string $precision, int $limit): int
    {
        $items = NewsItem::whereNotNull('url')->where('created_at', '>=', now()->subDays($days))
            ->where('published_precision', $precision === 'date' ? 'date' : 'scraped')
            ->orderByDesc('id')->limit($limit)->get();

        $this->info("Re-dating {$items->count()} stories with a {$precision} time from the last {$days} days.");
        $fixed = 0;
        $rank = ['scraped' => 0, 'date' => 1, 'time' => 2];

        foreach ($items as $item) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(self::EXTRACT_TIMEOUT_SECONDS)->get($item->url);
            } catch (\Throwable $e) {
                continue;
            }

            if (!$response->successful()) {
                continue;
            }

            $extracted = $this->runExtractor((string) $response->body());
            $date = $this->plausibleDate($extracted['date'] ?? null);

            if ($date === null) {
                continue;
            }

            $pagePrecision = $this->clockIn($extracted['date']);
            $have = $item->published_precision ?? 'time';

            // We fetch fresh feeds, so a page date more than a week before we
            // found the story is some other date on the page (a filing's
            // quarter, a related article), not its publication.
            if (strtotime($date) < $item->created_at->getTimestamp() - 7 * 86400) {
                $this->line("  {$item->id} {$item->source}: page says {$date}, found " . $item->created_at->toDateString() . " - not believed");
                continue;
            }

            if ($rank[$pagePrecision] > $rank[$have]) {
                $item->update(['published_at' => $date, 'published_precision' => $pagePrecision]);
                $fixed++;
                $this->line("  {$item->id} {$item->source}: {$have} -> {$pagePrecision} {$date}");
            }

            usleep(300000);
        }

        $this->info("re-dated {$fixed} of {$items->count()}");

        return self::SUCCESS;
    }

    private function processItem(NewsItem $item)
    {
        $job = ExtractionJob::create([
            'news_item_id' => $item->id,
            'extraction_status' => 'pending',
        ]);

        try {
            // The feed's own copy first. Trafilatura does not fail loudly on a
            // page whose article is rendered in the browser - it returns the
            // navigation menu, which is long enough to pass for an article and
            // be stored as a success. Preferring the network would mean
            // preferring that menu over the real text we already hold.
            // How this publisher should be read, where somebody has said so.
            // Left null the strategy is the general one: try what the feed
            // already gave us, then fetch the page.
            $strategy = $this->strategyFor($item);

            $extracted = null;

            // Where the pages refuse us but the publisher's own API does not.
            if ($strategy === 'wp_json') {
                $extracted = $this->fromWordPressApi($item);
            }

            if ($extracted === null && $strategy !== 'page_only') {
                $extracted = $this->fromFeed($item);
            }

            if ($extracted === null && !in_array($strategy, ['feed_only', 'wp_json'], true)) {
                $extracted = $this->extractWithTrafilatura($item->url);
            }

            // The feed gave us a teaser. Go and get the article, which is what
            // the link is for - a headline cannot be summarised or located, and
            // storing it as though it could is how a classification gets blamed
            // for an extraction failure.
            if ($strategy !== 'feed_only'
                && $extracted !== null
                && mb_strlen((string) ($extracted['text'] ?? ''))
                   < $this->minUsableChars($extracted['text'] ?? null)) {
                $fromPage = $this->extractWithTrafilatura($item->url);

                if ($fromPage !== null
                    && mb_strlen((string) ($fromPage['text'] ?? '')) > mb_strlen((string) $extracted['text'])) {
                    $extracted = $fromPage;
                }
            }

            // Still a headline. Say so plainly rather than passing it on: an
            // honest failure can be retried and reported, while a success
            // nobody questions is summarised from a title.
            //
            // Unless an editor has said this publisher will never give more.
            // A paywalled outlet's free opening is short but it is genuinely
            // theirs, and refusing it loses the outlet altogether - the point
            // of teaser_ok is that it is published as written and never
            // expanded, so its shortness costs nothing but length.
            // ⛔ No exception for a teaser any more. teaser_ok let a few hundred
            // characters through as though they were a story, and a few hundred
            // characters cannot be summarised or located - there is nothing in
            // them to process. Every one had to be deleted afterwards, and the
            // model calls spent on them were spent on nothing.
            if ($extracted !== null
                && mb_strlen((string) ($extracted['text'] ?? ''))
                   < $this->minUsableChars($extracted['text'] ?? null)) {
                // 'failed' rather than a new status: the table has a check
                // constraint listing the four it accepts, and inventing a fifth
                // would throw on every thin story. The distinction that matters
                // downstream is only whether classification may read it, and
                // 'failed' answers that correctly. What went wrong is recorded
                // in the method, where the next person will look.
                $job->update([
                    'extraction_status' => 'failed',
                    'extracted_text'    => $extracted['text'] ?? null,
                    'extraction_method' => 'too_thin_' . mb_strlen((string) ($extracted['text'] ?? '')) . 'ch',
                    'extracted_at'      => now(),
                ]);

                $this->warn("  THIN {$item->id}: " . mb_strlen((string) ($extracted['text'] ?? '')) . ' chars');

                return;
            }

            if ($extracted && !empty($extracted['text'])) {
                // Prefer the article's own publication time over whatever the
                // feed or section page implied - but only ever upward. A page
                // that states a clock time wins outright; a page that states
                // only a day improves on a scrape time and nothing else. It
                // used to overwrite a real feed time with midnight.
                if (!empty($extracted['date'])) {
                    $pagePrecision = $extracted['date_precision'] ?? 'time';
                    $have = $item->published_precision ?? 'time';
                    $rank = ['scraped' => 0, 'date' => 1, 'time' => 2];
                    // more than a week before we found it: some other date on the page
                    $believable = strtotime((string) $extracted['date']) >= $item->created_at->getTimestamp() - 7 * 86400;

                    if ($believable && ($rank[$pagePrecision] > $rank[$have] || $pagePrecision === 'time')) {
                        $item->update(['published_at' => $extracted['date'], 'published_precision' => $pagePrecision]);
                    }
                }

                $job->update([
                    'extracted_title' => mb_substr((string) ($extracted['title'] ?? $item->title), 0, 250),
                    'extracted_summary' => $extracted['summary'] ?? $item->summary,
                    'extracted_text' => $extracted['text'],
                    'extraction_status' => 'success',
                    'extraction_method' => $extracted['method'] ?? 'trafilatura',
                    'extracted_at' => now(),
                ]);
                $item->update(['status' => 'active']);
                $this->info("✓ Extracted (" . ($extracted['method'] ?? 'trafilatura') . "): {$item->id}");
            } else {
                $this->useFallback($item, $job);
            }
        } catch (\Exception $e) {
            $this->useFallback($item, $job, $e->getMessage());
        }
    }

    /**
     * Fetch and extract an article body.
     *
     * One process, not two. The previous version fetched the page in one
     * process and tried to read it from the stdin of another, which nothing
     * piped into - so extraction always received an empty string, always
     * returned None, and stored the literal text 'NONE' as a success.
     *
     * The URL is passed as an argument rather than interpolated into the Python
     * source: feed URLs come from third parties and a quote in one would
     * otherwise break out of the shell string.
     */
    /**
     * Fetch an article and extract its body.
     *
     * The page is fetched here rather than by trafilatura, because
     * trafilatura.fetch_url() sends its own user agent and several Malaysian
     * publishers - Malay Mail among them, the largest source we have - answer
     * it with nothing. The RSS fetcher reaches those same sites without
     * trouble, so the extractor now asks in the same voice.
     *
     * The HTML goes to trafilatura over stdin, so the URL never reaches a shell
     * command at all.
     */
    /**
     * The article as the feed published it, if it did.
     *
     * Most publishers put the whole thing in <content:encoded>, and for the
     * ones that render in the browser or refuse our crawler it is the only copy
     * obtainable at all. The row is consumed here: it exists to carry the text
     * from the fetch to this step, and keeping it afterwards would only grow a
     * second copy of the archive.
     */
    /**
     * What the source handbook says about reading this publisher.
     *
     * 'feed_only'  - the feed carries the whole article, so fetching the page
     *                spends a request to get back something worse. True of the
     *                big Malaysian publishers, whose pages are assembled in the
     *                browser: fetching them returns a navigation menu that
     *                looks enough like an article to be stored as one.
     * 'page_only'  - the feed's text is a teaser worth ignoring.
     * null         - the general strategy: feed first, then the page.
     */
    private function strategyFor(NewsItem $item): ?string
    {
        // ⛔ An aggregator credits the original newsroom in its items, so
        // $item->source can name a publisher that never served this URL. Only
        // honour a "the feed already has the whole article" rule when the link
        // actually points at that publisher - otherwise a wrapper URL inherits
        // the rule and the page is never fetched at all.
        $source = DB::table('sources')
            ->where('name', $item->source)
            ->whereNotNull('extraction_strategy')
            ->first(['extraction_strategy', 'base_url']);

        if (!$source) {
            return null;
        }

        if ($source->extraction_strategy === 'feed_only' && !$this->urlBelongsTo($item->url, $source->base_url)) {
            return null;
        }

        return in_array($source->extraction_strategy, ['feed_only', 'page_only', 'wp_json', 'teaser_ok'], true)
            ? $source->extraction_strategy
            : null;
    }

    /** Is this link actually on the publisher's own domain? */
    private function urlBelongsTo(?string $url, ?string $baseUrl): bool
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        $base = parse_url((string) $baseUrl, PHP_URL_HOST);

        if (!$host || !$base) {
            return false;
        }

        $strip = fn (string $h) => preg_replace('/^www\./', '', mb_strtolower($h));

        return $strip($host) === $strip($base);
    }

    /**
     * Ask a WordPress site for the post behind this URL.
     *
     * The slug is the last part of the address, which is how WordPress itself
     * addresses a post, so no guessing is involved. One post is requested by
     * slug rather than a page of recent ones: the story wanted may be days old
     * by the time extraction reaches it.
     */
    private function fromWordPressApi(NewsItem $item): ?array
    {
        $path = (string) parse_url((string) $item->url, PHP_URL_PATH);
        $slug = trim($path, '/');
        $slug = $slug === '' ? '' : basename($slug);

        if ($slug === '') {
            return null;
        }

        $base = DB::table('sources')->where('name', $item->source)->value('base_url');

        if (!$base) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::EXTRACT_TIMEOUT_SECONDS)
                ->get(rtrim($base, '/') . '/wp-json/wp/v2/posts', ['slug' => $slug]);

            if (!$response->successful()) {
                return null;
            }

            $post = $response->json(0);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($post)) {
            return null;
        }

        $html = (string) ($post['content']['rendered'] ?? '');
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));

        if (mb_strlen($text) < self::MIN_EXTRACT_CHARS) {
            return null;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) > self::MAX_WORDS) {
            $text = implode(' ', array_slice($words, 0, self::MAX_WORDS));
        }

        return [
            'title'   => trim(html_entity_decode(strip_tags((string) ($post['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5)) ?: null,
            'summary' => null,
            'text'    => $text,
            'date'    => $this->plausibleDate($post['date_gmt'] ?? $post['date'] ?? null),
            'date_precision' => $this->clockIn($post['date_gmt'] ?? $post['date'] ?? null),
            'method'  => 'wp_json',
        ];
    }

    private function fromFeed(NewsItem $item): ?array
    {
        $row = DB::table('feed_contents')->where('url_hash', sha1((string) $item->url))->first();

        if (!$row || mb_strlen((string) $row->text) < self::MIN_EXTRACT_CHARS) {
            return null;
        }

        DB::table('feed_contents')->where('id', $row->id)->delete();

        return [
            'title'   => $item->title,
            'text'    => $row->text,
            'summary' => mb_substr($row->text, 0, 500),
            'date'    => null,
            'method'  => 'feed_content',
        ];
    }

    private function extractWithTrafilatura(string $url): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent'      => self::USER_AGENT,
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-MY,en;q=0.9,ms;q=0.8',
            ])
                ->timeout(self::EXTRACT_TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $html = $response->body();

        } catch (\Throwable $e) {
            return null;
        }

        if (trim($html) === '') {
            return null;
        }

        $decoded = $this->runExtractor($html);

        if (!is_array($decoded) || empty($decoded['text'])) {
            return null;
        }

        $text = trim((string) $decoded['text']);

        // A single stray character is not an article. Without this guard the
        // same shape of bug as the original 'NONE' recurs: a technically
        // non-empty string recorded as a successful extraction.
        if (mb_strlen($text) < self::MIN_EXTRACT_CHARS) {
            return null;
        }

        // Manual V6 section 9: keep roughly the first 500 words. The inverted
        // pyramid puts the facts at the top, and the rest is prompt cost.
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) > self::MAX_WORDS) {
            $text = implode(' ', array_slice($words, 0, self::MAX_WORDS));
        }

        return [
            'title'   => $decoded['title'] ?: null,
            'summary' => null,
            'text'    => $text,
            'date'    => $this->plausibleDate($decoded['date'] ?? null),
            'date_precision' => $this->clockIn($decoded['date'] ?? null),
            // ⛔ Without this key the success line below threw "Undefined array
            // key", the catch swallowed it, and useFallback overwrote a
            // perfectly good article with the feed's teaser. Every page-fetched
            // extraction on the site failed this way - BBC Sport, Channel News
            // Asia, Al Jazeera, Sportstar - and each looked like a publisher
            // that could not be read.
            'method'  => 'trafilatura',
        ];
    }

    /** Run trafilatura over HTML supplied on stdin. */
    /**
     * The article's stated publication time, when it is believable.
     *
     * A page claiming tomorrow, or 1970, is reporting broken metadata rather
     * than a publication time, and the ingested date is the better guess.
     */
    /** 'time' when the raw value carries a clock, 'date' when it is a day only. */
    private function clockIn($raw): string
    {
        return is_string($raw) && preg_match('/\d{1,2}:\d{2}/', $raw) ? 'time' : 'date';
    }

    private function plausibleDate($raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $ts = strtotime($raw);

        if ($ts === false) {
            return null;
        }

        $now = time();

        if ($ts > $now + 3600) {
            return null;
        }

        if ($ts < $now - (400 * 86400)) {
            return null;
        }

        return gmdate('c', $ts);
    }

    private function runExtractor(string $html): ?array
    {
        $script = <<<'PY'
import json, sys
import trafilatura

# The page is passed as a file. It used to arrive on stdin, which deadlocked on
# anything large: trafilatura's warnings filled an unread stderr pipe, this
# process stopped reading stdin to wait, and the caller stopped writing to wait
# for us.
with open(sys.argv[1], encoding='utf-8', errors='replace') as handle:
    html = handle.read()
out = {"text": None, "title": None, "date": None}

if html.strip():
    out["text"] = trafilatura.extract(
        html,
        include_comments=False,
        # Tables are content, not furniture, on a page that is mostly table: a
        # Bursa filing mirrored by KLSE Screener extracted to nothing with these
        # off and 2,505 characters with them on. Measured on ordinary articles
        # first - The Edge and New Straits Times both returned exactly the same
        # text either way - so this buys the filings without costing the news.
        include_tables=True,
        favor_precision=True,
    )
    try:
        meta = trafilatura.extract_metadata(html)
        if meta is not None:
            out["title"] = meta.title
            # The article's own publication time. Authoritative: a section page
            # has none, and some feeds mislabel their offset.
            out["date"] = meta.date
    except Exception:
        pass

    # trafilatura returns the DAY only. The page usually states the clock too
    # - in JSON-LD datePublished or the article:published_time meta - and a
    # day without a clock was being treated as no better than our own scrape
    # time. Take the full timestamp when the page has it.
    import re
    stamp = None
    for pattern in (r'"datePublished"\s*:\s*"([^"]{10,40})"',
                    r'property=["\']article:published_time["\'][^>]*content=["\']([^"\']{10,40})["\']',
                    r'content=["\']([^"\']{10,40})["\'][^>]*property=["\']article:published_time["\']',
                    r'<time[^>]+datetime=["\']([^"\']{10,40})["\']'):
        m = re.search(pattern, html)
        if m and re.search(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}', m.group(1)):
            stamp = m.group(1)
            break
    if stamp is None:
        try:
            from htmldate import find_date
            found = find_date(html, extensive_search=True, original_date=True, outputformat="%Y-%m-%dT%H:%M:%S")
            if found and not found.endswith("T00:00:00"):
                stamp = found
        except Exception:
            pass
    if stamp:
        out["date"] = stamp

print(json.dumps(out))
PY;

        // The page goes to disk and the extractor is told where to find it.
        // Nothing large crosses a pipe, so there is nothing to deadlock, and
        // stderr is discarded rather than left to fill a buffer nobody reads.
        $tmp = tempnam(sys_get_temp_dir(), 'np_extract_');

        if ($tmp === false || file_put_contents($tmp, $html) === false) {
            return null;
        }

        try {
            $command = sprintf(
                'timeout %d python3 -c %s %s 2>/dev/null',
                self::EXTRACT_TIMEOUT_SECONDS,
                escapeshellarg($script),
                escapeshellarg($tmp)
            );

            $output = shell_exec($command);
        } finally {
            @unlink($tmp);
        }

        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        $decoded = json_decode(trim($output), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function useFallback(NewsItem $item, ExtractionJob $job, string $error = null)
    {
        // Fallback order: feed summary → cleaned summary → minimal preserved raw summary
        $summary = $item->summary;

        // ⛔ The feed's own teaser is only a fallback when there is enough of
        // it to be an article. A thirty-one character summary is the headline
        // again, and passing it on as fallback_used told the classifier it was
        // reading a story - which is how a summary came to be written from a
        // title and a location guessed from nothing.
        // Some publishers will never give more than a teaser: they block
        // automated reads of their articles and offer a short feed, which is a
        // choice of theirs to respect rather than work around. Where an editor
        // has said so, the teaser is accepted and published as the publisher's
        // own words - never expanded into a summary, which is what turned a
        // headline into invented fuel prices.
        $teaserOk = $this->strategyFor($item) === 'teaser_ok';

        $usable = $summary !== null
            && ($teaserOk ? mb_strlen($summary) > 0 : mb_strlen($summary) >= $this->minUsableChars($summary));

        $job->update([
            'extracted_title' => $item->title,
            'extracted_summary' => $summary,
            'extracted_text' => $summary, // use summary as text fallback
            'extraction_status' => $usable ? 'fallback_used' : 'failed',
            'extraction_method' => $usable
                ? 'feed_summary_fallback'
                : 'too_thin_' . mb_strlen((string) $summary) . 'ch',
            'extracted_at' => now(),
        ]);

        if ($error) {
            Log::warning('Extraction fallback triggered', [
                'news_item_id' => $item->id,
                'error' => $error,
            ]);
        }

        if ($summary) {
            $item->update(['status' => 'active']);
        }

        $this->warn("↪ Fallback used for {$item->id}" . ($error ? " ({$error})" : ''));
    }
}

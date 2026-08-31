<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\ExtractionJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ExtractArticleContent extends Command
{
    protected $signature = 'ingest:extract {--news_item_id=}';
    protected $description = 'Extract content from news items';

    /** Manual V6 section 9: keep roughly the first 500 words. */
    private const MAX_WORDS = 500;

    /** A page that has not answered in this long is not worth waiting for. */
    private const EXTRACT_TIMEOUT_SECONDS = 25;

    /** Below this, whatever came back is not an article body. */
    private const MIN_EXTRACT_CHARS = 200;

    /** The same voice the RSS fetcher uses, which these sites answer. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    public function handle()
    {
        $newsItemId = $this->option('news_item_id');

        $query = NewsItem::whereDoesntHave('extractionJob', function ($q) {
            $q->whereIn('extraction_status', ['success', 'fallback_used']);
        });

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

    private function processItem(NewsItem $item)
    {
        $job = ExtractionJob::create([
            'news_item_id' => $item->id,
            'extraction_status' => 'pending',
        ]);

        try {
            // Try trafilatura first
            $extracted = $this->extractWithTrafilatura($item->url);

            if ($extracted && !empty($extracted['text'])) {
                $job->update([
                    'extracted_title' => mb_substr((string) ($extracted['title'] ?? $item->title), 0, 250),
                    'extracted_summary' => $extracted['summary'] ?? $item->summary,
                    'extracted_text' => $extracted['text'],
                    'extraction_status' => 'success',
                    'extraction_method' => 'trafilatura',
                    'extracted_at' => now(),
                ]);
                $item->update(['status' => 'active']);
                $this->info("✓ Extracted (trafilatura): {$item->id}");
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
        ];
    }

    /** Run trafilatura over HTML supplied on stdin. */
    private function runExtractor(string $html): ?array
    {
        $script = <<<'PY'
import json, sys
import trafilatura

html = sys.stdin.read()
out = {"text": None, "title": None}

if html.strip():
    out["text"] = trafilatura.extract(
        html,
        include_comments=False,
        include_tables=False,
        favor_precision=True,
    )
    try:
        meta = trafilatura.extract_metadata(html)
        if meta is not None:
            out["title"] = meta.title
    except Exception:
        pass

print(json.dumps(out))
PY;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = sprintf(
            'timeout %d python3 -c %s',
            self::EXTRACT_TIMEOUT_SECONDS,
            escapeshellarg($script)
        );

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $html);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

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

        $job->update([
            'extracted_title' => $item->title,
            'extracted_summary' => $summary,
            'extracted_text' => $summary, // use summary as text fallback
            'extraction_status' => $summary ? 'fallback_used' : 'failed',
            'extraction_method' => $summary ? 'feed_summary_fallback' : 'failed',
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

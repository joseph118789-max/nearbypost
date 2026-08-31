<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Translate stories into the reading languages.
 *
 * Enrichment already returns translations for anything it processes, but that
 * only covers stories classified after the feature existed. Everything older
 * still reads in its source language, which is what a Chinese reader actually
 * sees: a Chinese interface wrapped around English headlines.
 *
 * Re-running full enrichment to get translations was the obvious fix and the
 * wrong one. That call carries the whole 156-row category taxonomy and redoes
 * classification, geocoding hints and validation, so it cost about seventeen
 * seconds a story to obtain three short strings.
 *
 * This command asks for translation and nothing else, and asks for several
 * stories at once. The prompt is a fraction of the size and one call covers a
 * batch, which is roughly two orders of magnitude cheaper per story.
 *
 * Existing translations are never overwritten: a story already translated is
 * skipped, so this is safe to run repeatedly and safe to leave on a schedule.
 */
class TranslateNewsItems extends Command
{
    protected $signature = 'ingest:translate
        {--limit=200 : Maximum stories to translate this run}
        {--batch=8 : Stories per model call}
        {--days=7 : How far back to look}
        {--locale= : Only fill this locale}';

    protected $description = 'Translate story headlines and summaries into the reading languages';

    private const MODEL   = 'deepseek-chat';
    private const LOCALES = ['en', 'ms', 'zh'];

    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'ms' => 'Malay (Bahasa Melayu)',
        'zh' => 'Simplified Chinese',
    ];

    public function handle(): int
    {
        $apiKey = config('services.deepseek.key');

        if (!$apiKey) {
            $this->error('DeepSeek API key not configured');
            return 1;
        }

        $locales = $this->option('locale') ? [$this->option('locale')] : self::LOCALES;
        $pending = $this->pending((int) $this->option('days'), (int) $this->option('limit'), $locales);

        if ($pending->isEmpty()) {
            $this->info('Nothing to translate.');
            return 0;
        }

        $this->info("Translating {$pending->count()} stor(ies) into: " . implode(', ', $locales));

        $done = 0;
        $failed = 0;

        foreach ($pending->chunk(max(1, (int) $this->option('batch'))) as $batch) {
            try {
                $translated = $this->translateBatch($apiKey, $batch->all(), $locales);
                $done += $translated;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('  batch failed: ' . $e->getMessage());
                Log::warning('Translation batch failed', ['error' => $e->getMessage()]);
            }
        }

        $this->info("Done. translated={$done} failed_batches={$failed}");

        return 0;
    }

    /**
     * Stories the feed is serving that are missing at least one language.
     *
     * Driven from feed_ready_items rather than news_items: translating a story
     * nobody can reach spends money for no reader.
     */
    private function pending(int $days, int $limit, array $locales)
    {
        return DB::table('feed_ready_items as f')
            ->join('news_items as n', 'n.id', '=', 'f.news_item_id')
            ->where('f.is_active', true)
            ->where('f.published_at', '>=', now()->subDays($days))
            ->whereRaw(
                '(SELECT count(*) FROM news_translations t
                   WHERE t.news_item_id = f.news_item_id AND t.locale IN (' .
                   implode(',', array_fill(0, count($locales), '?')) . ')) < ?',
                array_merge($locales, [count($locales)])
            )
            ->orderByDesc('f.published_at')
            ->limit($limit)
            ->get([
                'f.news_item_id as id',
                'n.title',
                DB::raw('COALESCE(n.ai_summary, n.summary) as summary'),
            ]);
    }

    private function translateBatch(string $apiKey, array $items, array $locales): int
    {
        $payload = [];

        foreach ($items as $item) {
            $payload[] = [
                'id'      => (int) $item->id,
                'title'   => mb_substr((string) $item->title, 0, 400),
                'summary' => mb_substr((string) ($item->summary ?? ''), 0, 700),
            ];
        }

        $parsed = $this->callModel($apiKey, $this->buildPrompt($payload, $locales));
        $count  = 0;

        foreach ($parsed as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            foreach ($locales as $locale) {
                $title = trim((string) ($row[$locale]['title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                DB::table('news_translations')->updateOrInsert(
                    ['news_item_id' => $id, 'locale' => $locale],
                    [
                        'title'      => mb_substr($title, 0, 550),
                        'summary'    => mb_substr(trim((string) ($row[$locale]['summary'] ?? '')), 0, 1200) ?: null,
                        'model'      => self::MODEL,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            DB::table('news_items')->where('id', $id)->update(['translated_at' => now()]);

            $this->line('  ' . $id . ' ok');
            $count++;
        }

        return $count;
    }

    private function buildPrompt(array $items, array $locales): string
    {
        $languages = [];

        foreach ($locales as $locale) {
            $languages[] = '"' . $locale . '": {"title": "headline in ' . self::LANGUAGE_NAMES[$locale]
                . '", "summary": "summary in ' . self::LANGUAGE_NAMES[$locale] . '"}';
        }

        $shape = '{"id": <the id>, ' . implode(', ', $languages) . '}';
        $json  = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
You are translating Malaysian news headlines and summaries. The input may be in any
language: English, Malay, Chinese, Tamil, Hindi, Japanese, Korean or another.

Respond with ONLY a valid JSON array, no markdown fences and no commentary. One
object per input item, in the same order:

[{$shape}]

Rules:
- Translate faithfully. Do not add, remove or embellish anything.
- Keep place names, people and organisations in the form a Malaysian reader
  would recognise. Do not translate proper nouns that are normally left as they
  are, and keep abbreviations like MACC, KLIA and RM.
- Keep the headline's register: a headline stays a headline, not a sentence.
- If an item is already in a target language, reproduce it cleanly rather than
  paraphrasing it.

Items:
{$json}
PROMPT;
    }

    /** @return list<array<string,mixed>> */
    private function callModel(string $apiKey, string $prompt): array
    {
        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->post('https://api.deepseek.com/v1/chat/completions', [
                'model'       => self::MODEL,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.2,
            ]);

        if (!$response->successful()) {
            throw new \Exception('DeepSeek API error: ' . $response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content)) {
            throw new \Exception('DeepSeek response missing content');
        }

        $content = preg_replace('/^```(?:json)?\s*/', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            // Recover the first array in the response if the model wrapped it.
            if (preg_match('/\[.*\]/s', $content, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }

        if (!is_array($parsed)) {
            throw new \Exception('Could not parse translation response as JSON');
        }

        return $parsed;
    }
}

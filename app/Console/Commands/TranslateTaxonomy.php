<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fill in the sub-category names in Malay and Chinese.
 *
 * The 21 primary categories were written by hand in the migration: they are few,
 * they appear in page titles and URLs, and they are worth being exact about. The
 * 156 sub-categories are a different proposition - too many to hand-write
 * carefully, and low enough stakes that a good translation beats a delayed one.
 *
 * Run once. It skips anything already filled, so it is safe to re-run after new
 * sub-categories are added, and it will only translate those.
 *
 * These are short interface labels, not prose, so the prompt asks for the term a
 * Malaysian news site would actually print on a chip - "Sukan" rather than a
 * literal rendering of "Sports".
 */
class TranslateTaxonomy extends Command
{
    protected $signature = 'categories:translate
        {--batch=25 : Sub-categories per model call}
        {--force : Re-translate entries that already have names}';

    protected $description = 'Translate sub-category names into Malay and Chinese';

    private const MODEL = 'deepseek-chat';

    public function handle(): int
    {
        $apiKey = (\App\Services\Ai\AiRouter::for('taxonomy_translate')->isConfigured() ? 'via-ai-panel' : '');

        if (!$apiKey) {
            $this->error('DeepSeek API key not configured');
            return 1;
        }

        $query = DB::table('subcategories')->orderBy('id');

        if (!$this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('sub_category_ms')->orWhereNull('sub_category_zh');
            });
        }

        $rows = $query->get(['id', 'primary_category', 'sub_category']);

        if ($rows->isEmpty()) {
            $this->info('All sub-categories already translated.');
            return 0;
        }

        $this->info("Translating {$rows->count()} sub-category name(s).");

        $done = 0;

        foreach ($rows->chunk(max(1, (int) $this->option('batch'))) as $chunk) {
            try {
                $done += $this->translateChunk($apiKey, $chunk->all());
            } catch (\Throwable $e) {
                $this->warn('  batch failed: ' . $e->getMessage());
            }
        }

        $this->info("Done. translated={$done}");

        return 0;
    }

    private function translateChunk(string $apiKey, array $rows): int
    {
        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id'       => (int) $row->id,
                'category' => $row->primary_category,
                'name'     => $row->sub_category,
            ];
        }

        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
Translate these news sub-category labels into Malay and Simplified Chinese.

These are short interface labels shown as chips on a Malaysian news site, not
prose. Give the term a Malaysian newsroom would actually print - natural and
short - rather than a literal word-for-word rendering. Keep them title case in
Malay. The parent category is given for context only; do not translate it.

Respond with ONLY a JSON array, one object per input, same order:
[{"id": <id>, "ms": "<Malay label>", "zh": "<Chinese label>"}]

Items:
{$json}
PROMPT;

        $response = \App\Services\Ai\AiRouter::for('taxonomy_translate')->post(120, [
                'model'       => self::MODEL,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.2,
            ]);

        if (!$response->successful()) {
            throw new \Exception('DeepSeek API error: ' . $response->status());
        }

        $content = (string) $response->json('choices.0.message.content');
        $content = preg_replace('/^```(?:json)?\s*/', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);

        $parsed = json_decode($content, true);

        if (!is_array($parsed) && preg_match('/\[.*\]/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }

        if (!is_array($parsed)) {
            throw new \Exception('Could not parse response as JSON');
        }

        $count = 0;

        foreach ($parsed as $row) {
            $id = (int) ($row['id'] ?? 0);
            $ms = trim((string) ($row['ms'] ?? ''));
            $zh = trim((string) ($row['zh'] ?? ''));

            if ($id === 0 || $ms === '' || $zh === '') {
                continue;
            }

            DB::table('subcategories')->where('id', $id)->update([
                'sub_category_ms' => mb_substr($ms, 0, 120),
                'sub_category_zh' => mb_substr($zh, 0, 120),
                'updated_at'      => now(),
            ]);

            $count++;
        }

        $this->line("  translated {$count} of " . count($rows));

        return $count;
    }
}

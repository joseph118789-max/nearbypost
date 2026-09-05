<?php

namespace App\Console\Commands;

use App\Services\Ai\DeepSeekAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Our own headline for stories enriched before the prompt asked for one
 * (owner, 4 Sep 2026: "rephrase the title ... this avoids news agencies saying
 * we copied their news"). The model gets the publisher's headline and our
 * summary and returns a headline in the same language; it is written to
 * news_items.ai_title and onto the serving rows. Stories that already have
 * one are skipped, so the command is safe to run again.
 */
class RetitleStories extends Command
{
    protected $signature = 'ingest:retitle {--limit=200 : stories per run} {--batch=25 : stories per model call} {--dry-run : show, write nothing}';
    protected $description = 'Write our own headline for served stories that still show the publisher\'s';

    private const LANGS = ['en' => 'English', 'ms' => 'Malay', 'zh' => 'Chinese (as written, simplified or traditional)', 'ta' => 'Tamil'];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $batch = max(1, min(40, (int) $this->option('batch')));

        $rows = DB::table('news_items as n')->join('feed_ready_items as f', 'f.news_item_id', '=', 'n.id')
            ->where('f.is_active', true)->whereNull('n.ai_title')->where('n.origin', 'scraper')
            ->orderByDesc('n.published_at')->distinct()->limit($limit)
            ->get(['n.id', 'n.title', 'n.source_language', DB::raw('COALESCE(n.ai_summary, n.summary) as summary'), 'n.published_at']);

        $this->line(sprintf('Retitling %d stories.', $rows->count()));
        $done = 0;

        foreach ($rows->chunk($batch) as $chunk) {
            $items = $chunk->map(fn ($r) => ['id' => (int) $r->id, 'lang' => self::LANGS[$r->source_language] ?? 'the language of the headline',
                'headline' => mb_substr((string) $r->title, 0, 300), 'summary' => mb_substr((string) $r->summary, 0, 600)])->values()->all();

            // Measured 4 Sep 2026: about two replies in eight came back with prose around the
            // JSON, and each one left a story wearing the publisher's headline. The reply is now
            // read out of whatever the model wrapped it in, and asked for once more if that fails.
            $json = null;

            foreach ([0.4, 0.1] as $attempt => $temperature) {
                $prompt = $this->prompt($items);

                if ($attempt > 0) {
                    $prompt = "Your last reply could not be read. Answer with the JSON array and nothing else: no sentence before it, no explanation after it, no code fences.\n\n" . $prompt;
                }

                try {
                    $raw = \App\Services\Ai\AiRouter::for('retitle')->complete($prompt, $temperature);
                } catch (\Throwable $e) {
                    $this->error('  model call failed: ' . $e->getMessage());
                    continue 2;
                }

                $json = \App\Services\Ai\ModelJson::parse($raw);

                if (is_array($json)) {
                    if ($attempt > 0) { $this->line('  (the second attempt was readable)'); }
                    break;
                }
            }

            if (!is_array($json)) {
                $this->error('  reply could not be read as JSON, twice; this batch keeps its publishers\' headlines');
                continue;
            }

            // one object rather than the array of one that was asked for
            if (isset($json['title']) || isset($json['id'])) {
                $json = [$json];
            }

            $byId = $chunk->keyBy('id');

            foreach ($json as $row) {
                $id = (int) ($row['id'] ?? 0);
                $own = trim(preg_replace('/\s+/u', ' ', (string) ($row['title'] ?? '')), " \t\n\r\"'“”");
                $orig = $byId->get($id);

                if (!$orig || mb_strlen($own) < 10 || !EnrichWithAi::isOwnHeadline($own, $orig->title)) {
                    continue;
                }

                $own = mb_substr($own, 0, 200);
                $this->line(sprintf('  %d  %s', $id, $own));

                if (!$this->option('dry-run')) {
                    DB::table('news_items')->where('id', $id)->update(['ai_title' => $own]);
                    DB::table('feed_ready_items')->where('news_item_id', $id)->update(['title' => $own]);
                }

                $done++;
            }
        }

        $this->info(sprintf('Done. %d headlines written.', $done));

        return self::SUCCESS;
    }

    private function prompt(array $items): string
    {
        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
You write headlines for a local news site that summarises stories and links to the publisher.
For each item write OUR OWN headline in the language named in "lang" (the same language as the
publisher's headline). Rules:
- at most 90 characters, factual and plain, in your own wording;
- never reuse the publisher's headline or its phrasing; rewrite from the facts, and fold in the
  key point of the summary where that reads better;
- keep names, places, organisations, abbreviations (MACC, KLIA, RM) and numbers exactly;
- a headline, not a sentence with a full stop; no quotation marks around it; no clickbait.

Respond with ONLY a JSON array, no fences, one object per input item: [{"id": <id>, "title": "<headline>"}]

Items:
{$json}
PROMPT;
    }
}

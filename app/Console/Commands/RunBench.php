<?php

namespace App\Console\Commands;

use App\Services\Ai\Adapters;
use App\Services\Classification\CategoryScorer;
use App\Services\Knowledge\PromptAssembler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Score a model against answers a person has confirmed.
 *
 * This is the module the others depend on. Every change to the playbook, the
 * rules, the briefing or the model itself is a change to how the site judges
 * news, and until this existed there was no way to say whether a change made
 * things better or worse. Work was checked by reading a handful of stories and
 * forming an impression - which is not a method that survives being handed to
 * someone else, or to a different model.
 *
 * ⛔ IT WRITES NOTHING TO news_items. A bench run must never alter the feed:
 * the point is to ask what a model WOULD say, which is a different question
 * from what the site currently shows, and confusing the two would mean the act
 * of measuring changed the thing being measured.
 *
 * Run: php artisan bench:run
 *      php artisan bench:run --adapter=deepseek-reasoner
 *      php artisan bench:run --limit=40 --note="after the sport rule change"
 */
class RunBench extends Command
{
    protected $signature = 'bench:run
        {--adapter= : Which model to test (default: the configured one)}
        {--limit=0 : Only the first N bench items}
        {--note= : What this run was testing}';

    protected $description = 'Score a model against the confirmed answers in the bench';

    public function handle(): int
    {
        $adapter = Adapters::make($this->option('adapter') ?: null);

        if (!$adapter->isConfigured()) {
            $this->error("No key configured for {$adapter->key()}. Nothing to test with.");

            return 1;
        }

        $items = $this->items((int) $this->option('limit'));

        if ($items === []) {
            $this->warn('The bench is empty. Confirm some answers in the panel first.');

            return 1;
        }

        $this->info("Testing {$adapter->key()} ({$adapter->model()}) against " . count($items) . ' confirmed answers.');

        $runId = DB::table('bench_runs')->insertGetId([
            'adapter'        => $adapter->key(),
            'model'          => $adapter->model(),
            'prompt_version' => 'v6',
            'items'          => count($items),
            'note'           => $this->option('note') ? mb_substr((string) $this->option('note'), 0, 200) : null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $tally = ['keep' => 0, 'category' => 0, 'place' => 0, 'nowhere' => 0, 'nowhere_total' => 0, 'errors' => 0];
        $assembler = new PromptAssembler();

        foreach ($items as $i => $item) {
            $this->line(sprintf('  %2d/%d  %s', $i + 1, count($items), mb_substr($item->title, 0, 58)));

            try {
                $answer = $this->askModel($adapter, $assembler, $item);
            } catch (\Throwable $e) {
                $tally['errors']++;

                DB::table('bench_results')->insert([
                    'bench_run_id' => $runId,
                    'bench_item_id' => $item->id,
                    'error'        => mb_substr($e->getMessage(), 0, 500),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                continue;
            }

            $correct = $this->compare($item, $answer);

            foreach (['keep', 'category', 'place'] as $dimension) {
                if (($correct[$dimension] ?? null) === true) {
                    $tally[$dimension]++;
                }
            }

            // Scored separately because it is the answer this site gets wrong
            // most often, and an overall place score hides it: a model that
            // pins everything to its dateline still scores well on the stories
            // that genuinely have a location.
            if ($item->expect_nowhere) {
                $tally['nowhere_total']++;

                if (($correct['place'] ?? null) === true) {
                    $tally['nowhere']++;
                }
            }

            DB::table('bench_results')->insert([
                'bench_run_id'  => $runId,
                'bench_item_id' => $item->id,
                'got_keep'      => $answer['keep'],
                'got_category'  => $answer['category'],
                'got_sub'       => $answer['sub'],
                'got_place'     => $answer['place'],
                'correct'       => json_encode($correct),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $scored = count($items) - $tally['errors'];
        $scores = [
            'kept_or_discarded' => $this->pct($tally['keep'], $scored),
            'category'          => $this->pct($tally['category'], $scored),
            'place'             => $this->pct($tally['place'], $scored),
            'nowhere'           => $this->pct($tally['nowhere'], $tally['nowhere_total']),
            'errors'            => $tally['errors'],
            'scored'            => $scored,
        ];

        DB::table('bench_runs')->where('id', $runId)->update([
            'scores'     => json_encode($scores),
            'updated_at' => now(),
        ]);

        $this->newLine();
        $this->info("Run #{$runId} — {$adapter->key()} ({$adapter->model()})");
        $this->line(sprintf('  kept or discarded correctly   %s', $this->show($scores['kept_or_discarded'])));
        $this->line(sprintf('  category                      %s', $this->show($scores['category'])));
        $this->line(sprintf('  place                         %s', $this->show($scores['place'])));
        $this->line(sprintf('  of which "nowhere"            %s  (%d items)', $this->show($scores['nowhere']), $tally['nowhere_total']));

        if ($tally['errors'] > 0) {
            $this->warn("  {$tally['errors']} item(s) could not be scored.");
        }

        return 0;
    }

    /** @return list<object> */
    private function items(int $limit): array
    {
        // ⛔ Proposals are excluded. A bench is worth having because a person
        // confirmed each answer; scoring a model against a machine's own
        // opinion would always look good and mean nothing.
        $query = DB::table('bench_items')->where('proposed', false)->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->all();
    }

    /**
     * Ask the model, and reduce its reply to the four things being scored.
     *
     * @return array{keep: bool, category: ?string, sub: ?string, place: ?string}
     */
    private function askModel($adapter, PromptAssembler $assembler, object $item): array
    {
        $story = DB::table('news_items as n')
            ->leftJoin('extraction_jobs as e', function ($join) {
                $join->on('e.news_item_id', '=', 'n.id')
                     ->whereIn('e.extraction_status', ['success', 'fallback_used']);
            })
            ->where('n.id', $item->news_item_id)
            ->orderByDesc('e.id')
            ->select('n.title', 'n.source', 'e.extracted_text')
            ->first();

        if (!$story) {
            throw new \RuntimeException('The story is no longer in the database.');
        }

        $prompt = $assembler->build(
            (string) $story->title,
            (string) ($story->extracted_text ?: $story->title),
            (string) $story->source
        );

        $parsed = $this->parse($adapter->complete($prompt));

        // The winning category is decided by the scorer, not by the model, so
        // scoring the model's own favourite would measure something the site
        // never uses.
        $category = null;
        $sub = null;

        if (!empty($parsed['rel'])) {
            $outcome = (new CategoryScorer())->score(
                $parsed['rel'],
                $parsed['sub'] ?? [],
                ['gps' => ($parsed['g'] ?? 0) === 1, 'cap' => 1.0, 'slots' => []]
            );

            if ($outcome['valid'] ?? false) {
                $category = mb_strtolower((string) ($outcome['primary']['name'] ?? ''));
                $sub = $outcome['sub']['name'] ?? null;
            }
        }

        $place = trim((string) ($parsed['place'] ?? ''));

        return [
            'keep'     => (int) ($parsed['d'] ?? 0) !== 1,
            'category' => $category ?: null,
            'sub'      => $sub ?: null,
            'place'    => ($place === '' || mb_strtolower($place) === 'null') ? null : $place,
        ];
    }

    private function parse(string $raw): array
    {
        $content = preg_replace('/^```(?:json)?\s*/', '', trim($raw));
        $content = preg_replace('/```\s*$/', '', (string) $content);

        $parsed = json_decode(trim((string) $content), true);

        if (!is_array($parsed) && preg_match('/\{.*\}/s', (string) $content, $m)) {
            $parsed = json_decode($m[0], true);
        }

        if (!is_array($parsed)) {
            throw new \RuntimeException('The reply was not JSON.');
        }

        return $parsed;
    }

    /**
     * @return array{keep: bool, category: ?bool, place: ?bool}
     */
    private function compare(object $item, array $answer): array
    {
        $result = ['keep' => $answer['keep'] === (bool) $item->expect_keep];

        // A discarded story has no category or place to be right or wrong
        // about, so those stay null rather than counting as failures.
        if (!$item->expect_keep) {
            return $result + ['category' => null, 'place' => null];
        }

        $result['category'] = $item->expect_category
            ? mb_strtolower((string) $answer['category']) === mb_strtolower((string) $item->expect_category)
            : null;

        if ($item->expect_nowhere) {
            $result['place'] = $answer['place'] === null;
        } elseif ($item->expect_place) {
            $result['place'] = $this->placeMatches((string) $item->expect_place, (string) $answer['place']);
        } else {
            $result['place'] = null;
        }

        return $result;
    }

    /**
     * Places match when they name the same place, not the same string.
     *
     * "Ipoh" against "Bercham, Ipoh, Perak" is a right answer given more
     * precisely, and marking it wrong would push the site towards vaguer
     * locations - the opposite of what is wanted.
     */
    private function placeMatches(string $expected, string $got): bool
    {
        $normalise = fn (string $s) => mb_strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $s));

        $e = $normalise($expected);
        $g = $normalise($got);

        if ($e === '' || $g === '') {
            return false;
        }

        if (str_contains($g, $e) || str_contains($e, $g)) {
            return true;
        }

        // Otherwise: do they share their most specific part?
        $eHead = trim(explode(',', $expected)[0]);
        $gHead = trim(explode(',', $got)[0]);

        return $eHead !== '' && mb_strtolower($eHead) === mb_strtolower($gHead);
    }

    private function pct(int $hit, int $of): ?float
    {
        return $of > 0 ? round($hit * 100 / $of, 1) : null;
    }

    private function show(?float $pct): string
    {
        return $pct === null ? '  n/a' : sprintf('%5.1f%%', $pct);
    }
}

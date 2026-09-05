<?php

namespace App\Console\Commands;

use App\Services\Ai\AiRouter;
use App\Services\Community\DeepSeekCommunityModerationProvider;
use App\Services\Contribution\NewsworthinessReview;
use App\Services\Contribution\OutletFinder;
use App\Services\Geo\LatinName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Training for every AI task, not only the story pipeline.
 *
 *   php artisan ai:train newsworthiness --label=r1
 *   php artisan ai:train all --label=r1
 *
 * Each case goes through the SAME code path production uses. The task is
 * pointed at one model at a time (primary only, no helper) so what is measured
 * is that model with its own notes from the panel; the task's real settings are
 * put back afterwards, whatever happens.
 *
 * A score is only worth having if a person can check it, so every check here is
 * something concrete: a verdict that matches, a place name preserved, a
 * headline that is not a copy. Where a task cannot be checked that way it is
 * not scored at all rather than given a number nobody should trust.
 */
class TrainAiTasks extends Command
{
    protected $signature = 'ai:train
        {task : a task key, or "all"}
        {--label=r1 : which round this is}
        {--models=deepseek,openai : models to test}
        {--limit=0 : only the first N cases}
        {--changed= : what was changed before this round}';

    protected $description = 'Run a task\'s training cases through each model and record how it did';

    public function handle(): int
    {
        $models = array_filter(array_map('trim', explode(',', (string) $this->option('models'))));
        $tasks = $this->argument('task') === 'all'
            ? DB::table('ai_training_cases')->where('active', true)->distinct()->orderBy('task_key')->pluck('task_key')->all()
            : [$this->argument('task')];

        foreach ($tasks as $task) {
            $this->runTask($task, $models);
        }

        return self::SUCCESS;
    }

    private function runTask(string $task, array $models): void
    {
        $cases = DB::table('ai_training_cases')->where('task_key', $task)->where('active', true)->orderBy('id')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))->get();

        if ($cases->isEmpty()) {
            $this->warn("No training cases for {$task}. Seed some with ai:train-seed.");

            return;
        }

        $original = DB::table('ai_tasks')->where('key', $task)->first();
        $this->info(sprintf('%s — %d cases × %s', $task, $cases->count(), implode(' + ', $models)));

        try {
            foreach ($models as $model) {
                // this model alone answers, with its own notes, exactly as production would
                DB::table('ai_tasks')->where('key', $task)->update(['primary_provider' => $model, 'helper_provider' => null, 'second_helper' => null, 'mode' => 'primary']);
                AiRouter::forget();

                $problems = [];
                $scored = 0;
                $passed = 0;
                $errors = 0;
                $t0 = microtime(true);

                foreach ($cases as $case) {
                    $input = json_decode((string) $case->input, true) ?: [];
                    $expect = json_decode((string) $case->expect, true) ?: [];

                    try {
                        $answer = $this->runCase($task, $input);
                    } catch (\Throwable $e) {
                        $errors++;
                        $problems[] = [$case->id, $case->name, 'error: ' . mb_substr($e->getMessage(), 0, 120)];
                        continue;
                    }

                    $bad = $this->check($task, $input, $expect, $answer);
                    $scored++;
                    if ($bad === []) { $passed++; } else { $problems[] = [$case->id, $case->name, implode(' | ', $bad)]; }
                }

                $pct = $scored ? (int) round($passed * 100 / $scored) : null;
                $ms = (int) ((microtime(true) - $t0) * 1000 / max(1, $cases->count()));
                $this->line(sprintf('  %-10s %s%%  (%d of %d)  %d error(s)  %dms each', $model, $pct === null ? '-' : $pct, $passed, $scored, $errors, $ms));

                foreach ($problems as [, $name, $why]) { $this->line(sprintf('       %-42s %s', mb_substr($name, 0, 42), mb_substr($why, 0, 90))); }

                $this->record($task, $model, $cases->count(), $pct, $errors, $problems);
            }
        } finally {
            // the task's real settings go back even if something threw
            DB::table('ai_tasks')->where('key', $task)->update([
                'primary_provider' => $original->primary_provider, 'helper_provider' => $original->helper_provider,
                'second_helper' => $original->second_helper, 'mode' => $original->mode,
            ]);
            AiRouter::forget();
        }
    }

    /** Put one case through the task's real code path. */
    private function runCase(string $task, array $in): array
    {
        return match ($task) {
            'newsworthiness' => (function () use ($in) {
                $v = (new NewsworthinessReview())->review($in['section'] ?? 'nearme', $in['title'], $in['body'], $in['place'] ?? null);
                return ['ok' => (bool) $v['ok'], 'category' => $v['category'], 'place' => $v['place'], 'summary' => $v['summary']];
            })(),

            'community_review' => (function () use ($in) {
                $v = DeepSeekCommunityModerationProvider::make()->review([
                    'title' => $in['title'], 'body' => $in['body'], 'place' => $in['place'] ?? null,
                    'language' => $in['language'] ?? 'en', 'trigger' => 'submit', 'context' => null, 'image_path' => null,
                ]);
                return ['decision' => $v['decision'], 'flags' => $v['flags'] ?? [], 'title' => $v['improved_title'] ?? null, 'facts_preserved' => $v['facts_preserved'] ?? null];
            })(),

            'outlet_finder' => ['outlet' => (new OutletFinder())->propose($in['title'], $in['body'])['name'] ?? null],

            'place_names' => ['latin' => LatinName::viaModel($in['name'], $in['context'] ?? null)],

            'comment_moderation' => (function () use ($in) {
                $reply = AiRouter::for('comment_moderation')->complete(
                    "You moderate comments under community news reports in Malaysia and Singapore (English, Malay, Chinese, Tamil).\n"
                    . "Reply with JSON only: {\"action\": \"publish\" or \"hide\", \"why\": \"a few words\"}.\n"
                    . "Hide abuse, threats, hate, spam, advertising, and anything that publishes another person's private details.\n"
                    . "Publish ordinary disagreement, criticism and strong opinion.\n\nComment:\n" . $in['body'], 0.1);
                $p = $this->json($reply);
                return ['action' => mb_strtolower(trim((string) ($p['action'] ?? '')))];
            })(),

            'comment_translate' => (function () use ($in) {
                $names = ['en' => 'English', 'ms' => 'Malay (Bahasa Malaysia)', 'zh' => 'Simplified Chinese'];
                $to = $in['to'];
                $reply = AiRouter::for('comment_translate')->complete(
                    "Translate this reader comment from a local-news site into {$names[$to]}. Keep place names, names of people and numbers exactly as written. "
                    . "Reply with JSON only: {\"lang\": \"<ISO 639-1 code of the comment's own language>\", \"text\": \"<the translation>\"}. "
                    . "If the comment is already in {$names[$to]}, return it unchanged as text.\n\nComment:\n" . $in['body'], 0.1);
                $p = $this->json($reply);
                return ['text' => trim((string) ($p['text'] ?? '')), 'lang' => trim((string) ($p['lang'] ?? ''))];
            })(),

            'translate' => (function () use ($in) {
                $reply = AiRouter::for('translate')->complete($in['prompt'], 0.2);
                $p = $this->json($reply);
                $row = is_array($p) && isset($p[0]) ? $p[0] : $p;
                return ['ms' => (string) ($row['ms']['title'] ?? ''), 'zh' => (string) ($row['zh']['title'] ?? ''), 'en' => (string) ($row['en']['title'] ?? '')];
            })(),

            'retitle' => (function () use ($in) {
                $reply = AiRouter::for('retitle')->complete($in['prompt'], 0.4);
                $p = $this->json($reply);
                $row = is_array($p) && isset($p[0]) ? $p[0] : $p;
                return ['title' => trim((string) ($row['title'] ?? ''))];
            })(),

            'dedupe', 'dedupe_live' => (function () use ($in, $task) {
                $reply = AiRouter::for($task)->complete($in['prompt'], 0.1);
                $p = $this->json($reply);
                return ['same' => (bool) ($p['same'] ?? false)];
            })(),

            'marketplace_vetting' => (function () use ($in) {
                $reply = AiRouter::for('marketplace_vetting')->complete(
                    "You check business and service listings offered to neighbours on a Malaysian local-news site.\n"
                    . "Reply with JSON only: {\"decision\": \"publish\" or \"hold\" or \"reject\", \"why\": \"a few words\"}.\n"
                    . "Reject: anything illegal, a money-lending or investment-return offer, adult services, medical cures, fake documents, multi-level recruitment.\n"
                    . "Hold: a claim that cannot be checked, a licence or qualification asserted without a number, or contact details only in a chat app.\n"
                    . "Publish: an ordinary local trade or service with a plain description and a way to be contacted.\n\nListing:\n"
                    . $in['title'] . "\n" . $in['body'], 0.1);
                $p = $this->json($reply);
                return ['decision' => mb_strtolower(trim((string) ($p['decision'] ?? '')))];
            })(),

            default => throw new \RuntimeException('no runner for task ' . $task),
        };
    }

    /** What counts as wrong, per task. Anything that cannot be checked concretely is not checked. */
    private function check(string $task, array $in, array $want, array $got): array
    {
        $bad = [];
        $has = fn (string $needle, string $hay) => $needle === '' || mb_stripos($hay, $needle) !== false;

        switch ($task) {
            case 'newsworthiness':
                if (isset($want['ok']) && $got['ok'] !== (bool) $want['ok']) { $bad[] = ($want['ok'] ? 'refused it, should accept' : 'accepted it, should refuse'); }
                if (!empty($want['category']) && mb_strtolower((string) $got['category']) !== mb_strtolower($want['category'])) { $bad[] = 'category ' . ($got['category'] ?: 'none') . ' (want ' . $want['category'] . ')'; }
                if (array_key_exists('place', $want)) {
                    if ($want['place'] === null && $got['place'] !== null) { $bad[] = 'place ' . $got['place'] . ' (want national)'; }
                    if ($want['place'] !== null && !$has((string) $want['place'], (string) $got['place'])) { $bad[] = 'place ' . ($got['place'] ?: 'none') . ' (want ' . $want['place'] . ')'; }
                }
                break;

            case 'community_review':
                if (!empty($want['decision']) && $got['decision'] !== $want['decision']) { $bad[] = 'decision ' . $got['decision'] . ' (want ' . $want['decision'] . ')'; }
                // some cases only require that a post is NOT published; which of the other verdicts is the reviewer's to choose
                if (!empty($want['not']) && $got['decision'] === $want['not']) { $bad[] = 'said ' . $got['decision'] . ', which it must not'; }
                foreach (($want['flags'] ?? []) as $f) { if (!in_array($f, $got['flags'] ?? [], true)) { $bad[] = 'missed the flag ' . $f; } }
                if (($want['facts_preserved'] ?? null) === true && $got['facts_preserved'] === false) { $bad[] = 'said it changed the facts when it did not'; }
                break;

            case 'comment_moderation':
                if ($got['action'] !== $want['action']) { $bad[] = 'said ' . ($got['action'] ?: 'nothing') . ' (want ' . $want['action'] . ')'; }
                break;

            case 'marketplace_vetting':
                if ($got['decision'] !== $want['decision']) { $bad[] = 'said ' . ($got['decision'] ?: 'nothing') . ' (want ' . $want['decision'] . ')'; }
                break;

            case 'comment_translate':
                if (trim((string) $got['text']) === '') { $bad[] = 'returned nothing'; break; }
                foreach (($want['keeps'] ?? []) as $k) { if (!$has($k, (string) $got['text'])) { $bad[] = 'lost "' . $k . '"'; } }
                if (!empty($want['script'])) { $bad = array_merge($bad, $this->scriptCheck($got['text'], $want['script'], 'the translation')); }
                break;

            case 'translate':
                foreach (['ms', 'zh'] as $loc) {
                    if (trim((string) $got[$loc]) === '') { $bad[] = $loc . ' missing'; continue; }
                    foreach (($want['keeps'] ?? []) as $k) { if (!$has($k, (string) $got[$loc])) { $bad[] = $loc . ' lost "' . $k . '"'; } }
                }
                if (trim((string) $got['zh']) !== '') { $bad = array_merge($bad, $this->scriptCheck($got['zh'], 'han', 'zh')); }
                if (trim((string) $got['ms']) !== '' && preg_match('/\p{Han}/u', $got['ms'])) { $bad[] = 'ms came back in Chinese'; }
                break;

            case 'retitle':
                $t = (string) $got['title'];
                $flat = fn ($x) => trim(preg_replace('/[^a-z0-9 ]/', '', mb_strtolower((string) $x)));
                if ($t === '') { $bad[] = 'wrote no headline'; break; }
                if ($flat($t) === $flat($in['publisher_title'])) { $bad[] = 'copied the publisher word for word'; }
                if (mb_strlen($t) > 110) { $bad[] = 'too long, ' . mb_strlen($t) . ' characters'; }
                // A headline carries the telling figure, not every figure: "sells the stake for RM45m"
                // is a sub-editor's choice over "sells the 50% stake", and both are honest. So at
                // least one of the key figures must survive, not all of them.
                $keeps = $want['keeps'] ?? [];
                if ($keeps !== []) {
                    $kept = array_filter($keeps, fn ($k) => $has($k, $t));
                    if ($kept === []) { $bad[] = 'lost every figure and name that mattered (' . implode(', ', $keeps) . ')'; }
                }
                // Only figures that would mislead a reader count as invented: money, a percentage,
                // a year, a big number. A model writing "40 years" for "empat dekad" is translating
                // prose into a figure, which is what a sub-editor does, and both models were being
                // marked wrong for it.
                preg_match_all('/(?:RM\s?[\d,.]+[a-z]*|\d[\d,.]*\s?%|\d{3,})/i', $t, $nums);
                $source = $in['publisher_title'] . ' ' . ($in['summary'] ?? '');
                foreach ($nums[0] as $n) {
                    $digits = preg_replace('/[^\d]/', '', $n);
                    if ($digits !== '' && !$has($digits, preg_replace('/[^\d]/', ' ', $source)) && !$has($n, $source)) { $bad[] = 'invented the figure ' . $n; }
                }
                break;

            case 'dedupe':
            case 'dedupe_live':
                if ($got['same'] !== (bool) $want['same']) { $bad[] = $want['same'] ? 'missed a duplicate' : 'called two different stories the same'; }
                break;

            case 'outlet_finder':
                if (!$has((string) ($want['outlet'] ?? ''), (string) ($got['outlet'] ?? ''))) { $bad[] = 'said ' . ($got['outlet'] ?: 'nothing') . ' (want ' . $want['outlet'] . ')'; }
                break;

            case 'place_names':
                $latin = (string) ($got['latin'] ?? '');
                if ($latin === '') { $bad[] = 'returned nothing'; break; }
                if (preg_match('/[\p{Han}\p{Tamil}\p{Arabic}]/u', $latin)) { $bad[] = 'still not in Latin letters'; }
                if (!empty($want['contains']) && !$has($want['contains'], $latin)) { $bad[] = 'gave "' . $latin . '" (want it to contain ' . $want['contains'] . ')'; }
                break;
        }

        return $bad;
    }

    /** Is the text actually in the script it was asked for? */
    private function scriptCheck(string $text, string $script, string $label): array
    {
        if ($script === 'han' && !preg_match('/\p{Han}/u', $text)) { return [$label . ' is not in Chinese']; }
        if ($script === 'latin' && preg_match('/\p{Han}/u', $text)) { return [$label . ' came back in Chinese']; }

        return [];
    }

    private function json(string $raw): array
    {
        // the trainer must read a reply exactly as production now does, or it measures a
        // strictness the site no longer has
        $viaShared = \App\Services\Ai\ModelJson::parse($raw);

        if (is_array($viaShared)) { return $viaShared; }

        $c = preg_replace('/```\s*$/', '', (string) preg_replace('/^```(?:json)?\s*/', '', trim($raw)));
        $p = json_decode(trim((string) $c), true);

        if (!is_array($p) && preg_match('/[\{\[].*[\}\]]/s', (string) $c, $m)) { $p = json_decode($m[0], true); }

        if (!is_array($p)) { throw new \RuntimeException('reply was not JSON'); }

        return $p;
    }

    private function record(string $task, string $model, int $items, ?int $pct, int $errors, array $problems): void
    {
        $label = (string) $this->option('label');
        DB::table('ai_training_runs')->updateOrInsert(
            ['label' => $label, 'set' => 'train', 'provider_key' => $model, 'task_key' => $task],
            ['model' => AiRouter::providers()[$model]['model'] ?? null, 'items' => $items, 'keep_pct' => $pct,
             'errors' => $errors, 'note_chars' => (int) DB::table('ai_task_prompts')->where('task_key', $task)->where('provider_key', $model)->selectRaw('coalesce(length(addendum),0) as n')->value('n'),
             'changed' => $this->option('changed') ? mb_substr((string) $this->option('changed'), 0, 300) : null, 'created_at' => now()]
        );

        $id = DB::table('ai_training_runs')->where('label', $label)->where('set', 'train')->where('provider_key', $model)->where('task_key', $task)->value('id');
        DB::table('ai_training_misses')->where('run_id', $id)->delete();

        foreach ($problems as [$caseId, $name, $why]) {
            DB::table('ai_training_misses')->insert(['run_id' => $id, 'news_item_id' => (int) $caseId, 'title' => mb_substr($name, 0, 200), 'problem' => mb_substr($why, 0, 400)]);
        }
    }
}

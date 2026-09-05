<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The second look, taken after the stories are already on the site.
 *
 * Dedupe at ingest has to compare a Malay report against an English one, and
 * there is no honest way to make that reliable. "Bayi ditemui dalam tong
 * sanitari di lapangan terbang Pulau Pinang" and "Newborn baby found alive in
 * Penang airport toilet" share no word, no name and no number. Any prefilter
 * loose enough to nominate them nominates a hundred unrelated pairs too.
 *
 * But a story that has gone live has been TRANSLATED. Both copies now exist in
 * the same language, and the comparison stops being the kind this codebase
 * cannot do and becomes the kind it does well. So the cheap unreliable pass
 * stays where it is, catching most of it before anything is spent on
 * classification, and this runs afterwards over what actually reached the feed.
 *
 * WHICH COPY GOES. The earlier one stays; the later one is pulled. This is not
 * the quality judgement `ingest:dedupe` makes while both copies are still
 * unpublished. Once a story is live it has been served, linked and indexed, and
 * swapping it for a better-written twin costs a reader more than it gains them.
 */
class DedupeLiveStories extends Command
{
    protected $signature = 'ingest:dedupe-live
        {--days=3 : How far back to compare}
        {--locale=en : The language both copies are compared in}
        {--dry-run : Report what would be pulled and change nothing}
        {--no-ai : Only merge pairs that are obvious by characters}';

    protected $description = 'Re-check published stories for duplicates, now that both copies exist in one language';

    /**
     * Two headlines this alike, in one language, are one story - and they have
     * to be alike BOTH ways, in spelling and in the words they use, before
     * anything merges without being asked about.
     */
    private const OBVIOUS_SPELLING = 0.82;
    private const OBVIOUS_WORDS    = 0.75;

    /**
     * Above this, the model is asked. Under it, nothing is asked and nothing
     * merges.
     *
     * Measured on the live feed rather than guessed. similar_text put two known
     * duplicates at 0.60 and 0.47 and two unrelated business headlines at 0.52
     * - no threshold fits between those. Shared words separate them properly:
     * the same duplicates score 0.67 and 0.67, while the noisiest unrelated
     * pair in 11,935 live comparisons reaches 0.43. Two English headlines about
     * anything share a great deal of spelling; they only share NOUNS when they
     * are about the same event.
     */
    private const WORTH_ASKING = 0.40;

    /**
     * Words that carry no event in them, so sharing one means nothing.
     */
    private const FILLER = [
        'the','a','an','and','or','but','of','in','on','at','to','for','with','from',
        'by','as','is','are','was','were','be','been','after','before','over','under',
        'says','said','say','new','more','than','into','out','up','down','its','it',
        'his','her','their','this','that','these','those','has','have','had','will',
        'not','no','who','what','when','where','how','amid','still','again','two',
    ];

    /** Two stories a day and a half apart are not two reports of one event. */
    private const SAME_EVENT_HOURS = 36;

    /** Most questions to buy in one nightly run. */
    private const MAX_CONFIRMATIONS = 80;

    public function handle(): int
    {
        $dry    = (bool) $this->option('dry-run');
        $locale = (string) $this->option('locale');

        $live = $this->liveStories((int) $this->option('days'), $locale);

        if (count($live) < 2) {
            $this->info('Fewer than two published stories in the window. Nothing to compare.');

            return 0;
        }

        $this->info(sprintf('Comparing %d published stories in %s.', count($live), strtoupper($locale)));

        [$obvious, $toAsk] = $this->pairsWorthLookingAt($live);

        $this->line(sprintf('  alike on sight     : %d pairs', count($obvious)));

        $pairs = $obvious;

        if (!$this->option('no-ai') && $toAsk !== []) {
            $this->line(sprintf('  worth asking about : %d pairs', count($toAsk)));

            $confirmed = $this->confirmWithModel($toAsk, $live);

            $this->line(sprintf('  confirmed by model : %d of %d', count($confirmed), count($toAsk)));

            $pairs = array_merge($pairs, $confirmed);
        }

        if ($pairs === []) {
            $this->info('No duplicates among the published stories.');

            return 0;
        }

        return $this->pullLaterCopies($this->cluster($pairs), $live, $dry);
    }

    /**
     * Everything currently served, read as a reader would see it - the
     * translation, not the original.
     *
     * A story with no translation in this locale is skipped rather than
     * compared in its own language. Mixing the two is what makes the ingest
     * pass unreliable, and the whole point of running here is that it need not
     * be.
     */
    private function liveStories(int $days, string $locale): array
    {
        $rows = DB::table('feed_ready_items as f')
            ->join('news_items as n', 'n.id', '=', 'f.news_item_id')
            ->join('news_translations as t', function ($join) use ($locale) {
                $join->on('t.news_item_id', '=', 'n.id')->where('t.locale', '=', $locale);
            })
            ->whereNull('n.duplicate_of')
            ->where('n.discarded', false)
            ->where('n.published_at', '>=', now()->subDays($days))
            ->orderBy('n.published_at')
            ->get(['n.id', 'n.source', 'n.published_at', 't.title as title', 't.summary as summary']);

        $out = [];

        foreach ($rows as $r) {
            $out[$r->id] = [
                'title'     => (string) $r->title,
                'opening'   => mb_substr(preg_replace('/\s+/u', ' ', (string) $r->summary), 0, 240),
                'source'    => (string) $r->source,
                'published' => (string) $r->published_at,
            ];
        }

        return $out;
    }

    /**
     * Bucketed by a long word from the headline, so this is not every story
     * against every other one.
     *
     * @return array{0: list<array{0:int,1:int}>, 1: list<array{0:int,1:int,2:float}>}
     */
    private function pairsWorthLookingAt(array $stories): array
    {
        $buckets = [];

        foreach ($stories as $id => $s) {
            foreach ($this->longWords($s['title']) as $word) {
                $buckets[$word][] = $id;
            }
        }

        $obvious = [];
        $toAsk   = [];
        $tested  = [];

        foreach ($buckets as $bucket) {
            if (count($bucket) > 60) {
                continue;   // a word this common tells us nothing
            }

            for ($i = 0; $i < count($bucket); $i++) {
                for ($j = $i + 1; $j < count($bucket); $j++) {
                    [$a, $b] = [$bucket[$i], $bucket[$j]];
                    $key = min($a, $b) . ':' . max($a, $b);

                    if (isset($tested[$key])) {
                        continue;
                    }

                    $tested[$key] = true;

                    // One publisher twice is a correction or a follow-up.
                    if ($stories[$a]['source'] === $stories[$b]['source']) {
                        continue;
                    }

                    if (abs(strtotime($stories[$a]['published']) - strtotime($stories[$b]['published']))
                        > self::SAME_EVENT_HOURS * 3600) {
                        continue;
                    }

                    $words = $this->wordOverlap($stories[$a]['title'], $stories[$b]['title']);

                    if ($words < self::WORTH_ASKING) {
                        continue;
                    }

                    $spelling = $this->similarity($stories[$a]['title'], $stories[$b]['title']);

                    if ($words >= self::OBVIOUS_WORDS && $spelling >= self::OBVIOUS_SPELLING) {
                        $obvious[] = [$a, $b];
                    } else {
                        $toAsk[] = [$a, $b, $words];
                    }
                }
            }
        }

        // Strongest evidence first, so a capped run spends its questions on the
        // pairs most likely to be duplicates.
        usort($toAsk, fn ($x, $y) => $y[2] <=> $x[2]);

        return [$obvious, $this->dropAlreadyAnswered($toAsk)];
    }

    /** Questions already bought, by either pass, are not bought again. */
    private function dropAlreadyAnswered(array $candidates): array
    {
        $asked = DB::table('dedupe_verdicts')
            ->get(['story_a', 'story_b'])
            ->mapWithKeys(fn ($v) => [$v->story_a . ':' . $v->story_b => true]);

        $fresh = array_values(array_filter(
            $candidates,
            fn ($c) => !isset($asked[min($c[0], $c[1]) . ':' . max($c[0], $c[1])])
        ));

        return array_slice($fresh, 0, self::MAX_CONFIRMATIONS);
    }

    private function confirmWithModel(array $candidates, array $stories): array
    {
        // NOT env(). Once the config is cached - and in production it always
        // is - env() returns null here, so this method returned [] before
        // asking anything, while the run still printed "confirmed by model:
        // 0 of 1163". It read like a measurement and was really a switched-off
        // step, four times an hour, for as long as the cache stayed warm.
        //
        // Ask the router instead: the same question the control panel answers,
        // and it does not care whether the config is cached. And say so out
        // loud, because the whole cost of this bug was its silence.
        $ai = \App\Services\Ai\AiRouter::for('dedupe_live');

        if (!$ai->isConfigured()) {
            $this->warn('  no provider configured for the dedupe_live task - no pair can be confirmed.');

            return [];
        }

        $confirmed = [];

        foreach ($candidates as $candidate) {
            [$a, $b] = $candidate;

            // Deliberately the question ingest dedupe asks, word for word, so
            // the two passes cannot disagree about what a duplicate is.
            $question = "Two news items. Are they reporting THE SAME EVENT, so that showing both "
                . "would show a reader the same story twice?\n\n"
                . "Say NO unless one is a translation, a rewrite, or a wire copy of the other.\n"
                . "Two articles can cover one event and still be different stories, and both "
                . "belong in the feed: a crash report and the condolences for its victims, a "
                . "collision and a passenger's account of it, a verdict and the reaction to it. "
                . "Each tells a reader something the other does not.\n"
                . "Two air-quality readings taken at different hours are also separate.\n\n"
                . "A ({$stories[$a]['source']}): {$stories[$a]['title']}\n"
                . "{$stories[$a]['opening']}\n\n"
                . "B ({$stories[$b]['source']}): {$stories[$b]['title']}\n"
                . "{$stories[$b]['opening']}\n\n"
                . 'Answer with JSON only: {"same": true or false}';

            try {
                $response = $ai->post(60, [
                        'messages'    => [['role' => 'user', 'content' => $question]],
                        'temperature' => 0.1,
                        'max_tokens'  => 40,
                    ]);

                if (!$response->successful()) {
                    continue;
                }

                preg_match('/\{.*\}/s', (string) $response->json('choices.0.message.content'), $m);
                $verdict = json_decode($m[0] ?? '', true);

                if (!is_array($verdict)) {
                    continue;
                }

                // Anything short of an explicit yes leaves both stories alone.
                $same = ($verdict['same'] ?? false) === true;

                if ($same) {
                    $confirmed[] = [$a, $b];
                }

                // A no is an answer too, and cost the same to get.
                DB::table('dedupe_verdicts')->updateOrInsert(
                    ['story_a' => min($a, $b), 'story_b' => max($a, $b)],
                    ['same' => $same, 'shared_tokens' => null,
                     'created_at' => now(), 'updated_at' => now()]
                );
            } catch (\Throwable $e) {
                Log::warning('Live dedupe confirmation failed', ['error' => $e->getMessage()]);
            }
        }

        return $confirmed;
    }

    /**
     * Three reports of one event are one cluster, not three pairs - otherwise
     * A-B and B-C pull two copies and leave the reader holding A and C.
     */
    private function cluster(array $pairs): array
    {
        $parent = [];

        $find = function (int $x) use (&$parent, &$find): int {
            while (($parent[$x] ?? $x) !== $x) {
                $x = $parent[$x];
            }

            return $x;
        };

        foreach ($pairs as [$a, $b]) {
            $ra = $find($a);
            $rb = $find($b);

            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        }

        $ids = [];

        foreach ($pairs as [$a, $b]) {
            $ids[$a] = true;
            $ids[$b] = true;
        }

        $clusters = [];

        foreach (array_keys($ids) as $id) {
            $clusters[$find($id)][$id] = true;
        }

        return $clusters;
    }

    private function pullLaterCopies(array $clusters, array $stories, bool $dry): int
    {
        $pulled = 0;

        foreach ($clusters as $members) {
            $ids = array_keys($members);

            // Published first stays. It has been served, linked and indexed;
            // the later copy is the one nobody misses.
            usort($ids, fn ($x, $y) => [strtotime($stories[$x]['published']), $x]
                <=> [strtotime($stories[$y]['published']), $y]);

            $keeper = array_shift($ids);

            // The keeper is printed above the copies it replaces. A list of
            // what was pulled, with no sight of what it was pulled in favour
            // of, cannot be checked by the person reading it.
            $this->line(sprintf(
                "\n  KEEP  %-16s %s  (%s)",
                mb_substr($stories[$keeper]['source'], 0, 16),
                mb_substr($stories[$keeper]['title'], 0, 56),
                $stories[$keeper]['published']
            ));

            foreach ($ids as $id) {
                $this->line(sprintf(
                    '    %s %-16s %s  (%s)',
                    $dry ? 'would pull' : 'pulled    ',
                    mb_substr($stories[$id]['source'], 0, 16),
                    mb_substr($stories[$id]['title'], 0, 52),
                    $stories[$id]['published']
                ));

                $pulled++;

                if ($dry) {
                    continue;
                }

                DB::transaction(function () use ($id, $keeper) {
                    DB::table('news_items')->where('id', $id)->update([
                        'duplicate_of'     => $keeper,
                        'duplicate_reason' => 'published_recheck',
                        'discarded'        => true,
                        'status'           => 'removed',
                        'updated_at'       => now(),
                    ]);

                    // Marking it and leaving it served would be a record that
                    // we knew, kept beside the thing we knew about.
                    DB::table('feed_ready_items')->where('news_item_id', $id)->delete();
                });
            }
        }

        $this->info(sprintf(
            "\n%s %d published %s.",
            $dry ? 'Would pull' : 'Pulled',
            $pulled,
            $pulled === 1 ? 'duplicate' : 'duplicates'
        ));

        return 0;
    }

    private function similarity(string $a, string $b): float
    {
        $a = $this->normalise($a);
        $b = $this->normalise($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /**
     * How much of the shorter headline's vocabulary the longer one repeats.
     *
     * Divided by the SHORTER of the two, so a wire's terse headline is not
     * punished for being terse against a paper's long one - they are still the
     * same story.
     */
    private function wordOverlap(string $a, string $b): float
    {
        $wa = $this->contentWords($a);
        $wb = $this->contentWords($b);

        if ($wa === [] || $wb === []) {
            return 0.0;
        }

        return count(array_intersect($wa, $wb)) / min(count($wa), count($wb));
    }

    /** @return list<string> */
    private function contentWords(string $title): array
    {
        $words = preg_split('/\s+/u', $this->normalise($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => mb_strlen($w) >= 3 && !in_array($w, self::FILLER, true)
        )));
    }

    private function normalise(string $title): string
    {
        $text = mb_strtolower($title);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    private function longWords(string $title): array
    {
        $words = preg_split('/\s+/u', $this->normalise($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 5)));
    }
}

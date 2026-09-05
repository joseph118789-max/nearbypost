<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Collapse the same story arriving many times, before anyone pays to judge it.
 *
 * The fetcher already de-duplicates, but only within a single run: two
 * publishers carrying the same wire in the same fifteen-minute window are
 * caught, and the same story arriving an hour later is not. What accumulates is
 * mostly wire syndication, and it is worse than it sounds - measured on this
 * table, the largest cluster was one Bernama report of a 5pm Sarawak air
 * quality reading appearing five times: Bernama in English, Bernama in Malay,
 * Berita Harian, The Edge and The Malaysian Reserve. A reader in Sarawak, in a
 * haze emergency, would have opened the app to the same headline five times.
 *
 * TWO METHODS, BECAUSE ONE CANNOT DO BOTH JOBS.
 *
 * Within a language, headlines are compared character by character. The
 * threshold of 0.82 was measured on real pairs from this database - duplicates
 * score 85.5% to 91.7%, distinct stories 25% to 68.7% - and needs no model.
 *
 * Across languages that method scores near zero and no threshold rescues it.
 * So instead: rare numbers. "RM120.43 million" and "374" survive translation
 * exactly, while "5" and "2026" identify nothing, and which is which is decided
 * by how often each appears in the corpus rather than by guesswork. That
 * proposes candidates at about 83% precision - good enough to shortlist, not
 * good enough to merge on, because the 17% it gets wrong are distinct air
 * quality readings taken at different hours, exactly the stories it would hurt
 * most to lose. So a model settles those, and only those: about fifty pairs a
 * day, a few sen.
 *
 * Nothing is deleted. A duplicate keeps its row and gains a pointer to the copy
 * we kept, which is both undoable and useful - it records which publishers
 * carry which wire.
 */
class DedupeStories extends Command
{
    protected $signature = 'ingest:dedupe
        {--limit=3000   : How many stories to consider}
        {--days=2       : Compare against everything published in this many days}
        {--no-ai        : Skip the model confirmation; certain matches only}
        {--dry-run      : Report what would be merged, change nothing}';

    protected $description = 'Collapse repeats of the same story before classification pays for them';

    /** Measured on real pairs from this table; distinct stories top out at 0.687. */
    private const TITLE_SIMILARITY = 0.82;

    /**
     * Low on purpose. Nothing merges on this number - it decides only which
     * pairs are worth one question to the model.
     */
    private const TRANSLATED_SIMILARITY = 0.55;

    /**
     * Rare names and numbers two stories must share before a model is asked.
     *
     * Measured, not chosen: across 1,433 stories from two days, unrelated pairs
     * shared at most 3 and typically 0, while the nine reports of one ferry
     * collision shared a median of 6.
     */
    private const MIN_SHARED_TOKENS = 4;

    /** A token in more than this share of the corpus identifies nothing. */
    private const TOKEN_RARITY = 0.01;

    /**
     * Reports of one event arrive together. This is not a tuning knob but a
     * fact about duplicates, and it is what keeps the comparison affordable.
     */
    private const SAME_EVENT_HOURS = 36;

    /** Most confirmations to buy in one run, strongest evidence first. */
    private const MAX_CONFIRMATIONS = 150;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $stories = $this->pendingStories((int) $this->option('limit'));

        if ($stories === []) {
            $this->info('Nothing pending.');

            return 0;
        }

        $this->info(sprintf('Considering %d stories awaiting classification.', count($stories)));

        $pairs = $this->sameLanguagePairs($stories);
        $this->line(sprintf('  same language      : %d pairs', count($pairs)));

        if (!$this->option('no-ai')) {
            $candidates = $this->crossLanguageCandidates($stories);
            $this->line(sprintf('  cross language     : %d candidates to confirm', count($candidates)));

            // Pairs the token index cannot see, nominated from their English
            // translations. Merged into the same list so they face the same
            // model, the same verdict cache and the same per-run cap.
            $translated = $this->translatedPairs($stories);
            $seen = [];

            foreach ($candidates as $c) {
                $seen[min($c[0], $c[1]) . ':' . max($c[0], $c[1])] = true;
            }

            $fresh = array_values(array_filter(
                $translated,
                fn ($c) => !isset($seen[min($c[0], $c[1]) . ':' . max($c[0], $c[1])])
            ));

            $this->line(sprintf('  via translation    : %d further candidates', count($fresh)));

            $candidates = array_merge($candidates, $fresh);

            $confirmed = $this->confirmWithModel($candidates, $stories);
            $this->line(sprintf('  confirmed by model : %d of %d', count($confirmed), count($candidates)));

            $pairs = array_merge($pairs, $confirmed);
        }

        // A yes that was already paid for and never acted on.
        //
        // The verdict cache exists so the next run does not buy the same
        // question twice, and it did that by dropping every pair already
        // asked - which also threw away the answer. A pair confirmed in a run
        // that stopped before it merged (a timeout, the confirmation cap, a
        // crash) could then never be merged by any later run: it was filtered
        // out as "asked" forever, and its yes sat in the table doing nothing.
        // The Ayam Brand press release sat on the front page three times over
        // with its verdict already recorded.
        //
        // OUTSIDE the --no-ai guard on purpose. That flag means "buy no new
        // answers", not "ignore the answers we own". Applying these costs
        // nothing and asks nobody.
        $settled = $this->settledDuplicates($stories);

        if ($settled !== []) {
            $this->line(sprintf('  already answered   : %d pairs confirmed in an earlier run', count($settled)));
            $pairs = array_merge($pairs, $settled);
        }

        if ($pairs === []) {
            $this->info('No duplicates found.');

            return 0;
        }

        $clusters = $this->cluster($pairs);
        $merged   = 0;

        foreach ($clusters as $members) {
            $ids    = array_keys($members);
            $keeper = $this->pickKeeper($ids, $stories);

            // The keeper is printed above the copies it replaces. A list of
            // what was dropped, with no sight of what it was dropped in favour
            // of, cannot be checked by the person reading it.
            $this->line(sprintf(
                "
  KEEP  %-16s %s",
                mb_substr((string) $stories[$keeper]['source'], 0, 16),
                mb_substr($stories[$keeper]['title'], 0, 62)
            ));

            foreach ($ids as $id) {
                if ($id === $keeper) {
                    continue;
                }

                $this->line(sprintf(
                    '    %s %-16s %s',
                    $dry ? 'would drop' : 'dropped   ',
                    mb_substr((string) $stories[$id]['source'], 0, 16),
                    mb_substr($stories[$id]['title'], 0, 58)
                ));

                if (!$dry) {
                    DB::transaction(function () use ($id, $keeper, $stories) {
                        DB::table('news_items')->where('id', $id)->update([
                            // Not 'pending', so classification never picks it up.
                            'ai_status'        => 'duplicate',
                            'duplicate_of'     => $keeper,
                            'duplicate_reason' => $stories[$id]['lang'] === $stories[$keeper]['lang']
                                ? 'same_language'
                                : 'cross_language',
                            'updated_at'       => now(),
                        ]);

                        // A duplicate found late is already on the site. Marking
                        // it and leaving it served would be a record that we
                        // knew, kept beside the thing we knew about.
                        DB::table('feed_ready_items')
                            ->where('news_item_id', $id)
                            ->update(['is_active' => false, 'updated_at' => now()]);
                    });
                }

                $merged++;
            }
        }

        $this->info(sprintf(
            '%s %d repeats across %d stories (%.1f%%), keeping %d originals.',
            $dry ? 'Would drop' : 'Dropped',
            $merged,
            count($stories),
            100 * $merged / max(1, count($stories)),
            count($clusters)
        ));

        Log::info('Dedupe run', ['merged' => $merged, 'clusters' => count($clusters), 'dry' => $dry]);

        return 0;
    }

    // ── the corpus ────────────────────────────────────────────────────────

    private function pendingStories(int $limit): array
    {
        $rows = DB::table('news_items as n')
            ->leftJoin(DB::raw('lateral (select extracted_text from extraction_jobs x
                where x.news_item_id = n.id and x.extraction_status in (\'success\',\'fallback_used\')
                order by x.id desc limit 1) e'), DB::raw('true'), DB::raw('true'))
            // Everything recent, whether or not it has been judged. A story
            // already classified is exactly what a new copy needs comparing
            // against - that was the hole nine ferry reports came through.
            ->where('n.published_at', '>=', now()->subDays((int) $this->option('days')))
            ->whereNull('n.duplicate_of')
            ->where('n.origin', 'scraper')
            ->orderByDesc('n.published_at')
            ->limit($limit)
            ->get(['n.id', 'n.title', 'n.source', 'n.published_at', 'n.ai_status',
                DB::raw('coalesce(e.extracted_text, \'\') as body'),
                // Whether this copy actually reached readers. pickKeeper needs
                // it; the note there says why.
                DB::raw('exists(select 1 from feed_ready_items f
                                 where f.news_item_id = n.id and f.is_active) as served')]);

        $out = [];

        foreach ($rows as $r) {
            $blob = $r->title . ' ' . mb_substr($r->body, 0, 1500);

            $out[$r->id] = [
                'title'     => (string) $r->title,
                'source'    => (string) $r->source,
                'published' => (string) $r->published_at,
                'length'    => mb_strlen((string) $r->body),
                'lang'      => $this->detectLanguage($blob),
                'numbers'   => $this->tokensIn($blob),
                'judged'    => $r->ai_status !== 'pending',
                'served'    => (bool) $r->served,
                'english'   => null,
                'opening'   => mb_substr(preg_replace('/\s+/u', ' ', (string) $r->body), 0, 240),
            ];
        }

        // The same story in English, where it has one. Two reports of one
        // event share nothing a token index can see when one says "Pulau
        // Pinang" and the other says "Penang" - which is how a baby found
        // at Penang airport ran twice on the same morning.
        $english = DB::table('news_translations')
            ->whereIn('news_item_id', array_keys($out))
            ->where('locale', 'en')
            ->pluck('title', 'news_item_id');

        foreach ($english as $id => $title) {
            if (isset($out[$id])) {
                $out[$id]['english'] = (string) $title;
            }
        }

        return $out;
    }

    // ── within one language: characters ───────────────────────────────────

    private function sameLanguagePairs(array $stories): array
    {
        $ids   = array_keys($stories);
        $pairs = [];

        // Bucketed by a long word from the headline, so this is not a
        // comparison of every story against every other one.
        $buckets = [];

        foreach ($ids as $id) {
            foreach ($this->longWords($stories[$id]['title']) as $w) {
                $buckets[$w][] = $id;
            }
        }

        $tested = [];

        foreach ($buckets as $bucket) {
            if (count($bucket) > 60) {
                continue;   // a word this common tells us nothing
            }

            for ($i = 0; $i < count($bucket); $i++) {
                for ($j = $i + 1; $j < count($bucket); $j++) {
                    [$a, $b] = [$bucket[$i], $bucket[$j]];
                    $key = $a < $b ? "$a:$b" : "$b:$a";

                    if (isset($tested[$key])) {
                        continue;
                    }

                    $tested[$key] = true;

                    if ($stories[$a]['lang'] !== $stories[$b]['lang']) {
                        continue;
                    }

                    if ($this->titleSimilarity($stories[$a]['title'], $stories[$b]['title'])
                        >= self::TITLE_SIMILARITY) {
                        $pairs[] = [$a, $b];
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Pairs that only look alike once both are in English.
     *
     * The token index cannot reach these: "Bayi ditemui dalam tong sanitari di
     * lapangan terbang Pulau Pinang" and "Newborn baby found alive in Penang
     * airport toilet" share no word, no name and no number. Translated they are
     * plainly one event - but only 60% alike by characters, well under the 82%
     * that merges two headlines on sight, and dropping THAT threshold to 60% is
     * how baseball once got merged into basketball.
     *
     * So this merges nothing. It nominates the pair and the model decides, which
     * is the gate every cross-language pair already passes through. A loose
     * threshold is safe here in a way it is never safe deciding on its own.
     */
    private function translatedPairs(array $stories): array
    {
        $ids = array_keys(array_filter(
            $stories,
            fn ($s) => $s['english'] !== null && $s['english'] !== ''
        ));

        $pairs = [];

        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                [$a, $b] = [$ids[$i], $ids[$j]];

                // One publisher twice is a correction or a follow-up, and the
                // same-language path already looks at those.
                if ($stories[$a]['source'] === $stories[$b]['source']) {
                    continue;
                }

                if (abs(strtotime($stories[$a]['published']) - strtotime($stories[$b]['published']))
                    > self::SAME_EVENT_HOURS * 3600) {
                    continue;
                }

                if ($this->titleSimilarity($stories[$a]['english'], $stories[$b]['english'])
                    >= self::TRANSLATED_SIMILARITY) {
                    $pairs[] = [$a, $b, 0];
                }
            }
        }

        return $pairs;
    }

    // ── across languages: rare numbers, then a model ──────────────────────

    private function crossLanguageCandidates(array $stories): array
    {
        $df = [];

        foreach ($stories as $s) {
            foreach ($s['numbers'] as $n) {
                $df[$n] = ($df[$n] ?? 0) + 1;
            }
        }

        // A number in more than about one story in 250 is not an identifier.
        $rareCap = max(3, (int) (count($stories) * self::TOKEN_RARITY));
        $index   = [];

        foreach ($stories as $id => $s) {
            foreach ($s['numbers'] as $n) {
                if (($df[$n] ?? 0) <= $rareCap) {
                    $index[$n][] = $id;
                }
            }
        }

        $shared = [];

        foreach ($index as $ids) {
            // A token shared by a crowd is a topic, not an event.
            if (count($ids) > 12) {
                continue;
            }

            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    [$a, $b] = [$ids[$i], $ids[$j]];
                    $key = $a < $b ? "$a:$b" : "$b:$a";
                    $shared[$key] = ($shared[$key] ?? 0) + 1;
                }
            }
        }

        $candidates = [];

        foreach ($shared as $key => $count) {
            [$a, $b] = array_map('intval', explode(':', $key));

            if ($count < self::MIN_SHARED_TOKENS) {
                continue;
            }

            // Two stories a day and a half apart are not two reports of one
            // event, whatever vocabulary they happen to share.
            if (abs(strtotime($stories[$a]['published']) - strtotime($stories[$b]['published']))
                > self::SAME_EVENT_HOURS * 3600) {
                continue;
            }

            // Every pair, whatever language. The nine ferry reports were mostly
            // English against English, which a cross-language-only test never
            // looked at, and their headlines were too different for the title
            // comparison to reach.
            $candidates[] = [$a, $b, $count];
        }

        // Anything already settled is dropped before the cap is applied, so
        // the run's calls go to questions nobody has answered yet.
        $asked = DB::table('dedupe_verdicts')
            ->get(['story_a', 'story_b'])
            ->mapWithKeys(fn ($v) => [$v->story_a . ':' . $v->story_b => true]);

        $candidates = array_values(array_filter($candidates, function ($c) use ($asked) {
            [$a, $b] = [min($c[0], $c[1]), max($c[0], $c[1])];

            return !isset($asked[$a . ':' . $b]);
        }));

        // Strongest evidence first, so a capped run spends its calls on the
        // pairs most likely to be duplicates.
        usort($candidates, fn ($x, $y) => $y[2] <=> $x[2]);

        if (count($candidates) > self::MAX_CONFIRMATIONS) {
            $this->warn(sprintf(
                '  %d candidates found; confirming the strongest %d. Re-run to reach the rest.',
                count($candidates),
                self::MAX_CONFIRMATIONS
            ));

            $candidates = array_slice($candidates, 0, self::MAX_CONFIRMATIONS);
        }

        return $candidates;
    }

    /**
     * The fingerprint proposes; the model decides.
     *
     * Asked plainly, because the failure mode is specific and worth naming in
     * the question: two air quality reports from the same day are the same
     * topic and different stories, and merging them loses real news.
     */
    /**
     * Pairs a model already called duplicates, both halves still unmerged.
     *
     * pendingStories() excludes anything with a duplicate_of, so a pair whose
     * two ids are both still in $stories is by definition a merge that has not
     * happened yet.
     */
    private function settledDuplicates(array $stories): array
    {
        $ids = array_keys($stories);

        if ($ids === []) {
            return [];
        }

        return DB::table('dedupe_verdicts')
            ->where('same', true)
            ->whereIn('story_a', $ids)
            ->whereIn('story_b', $ids)
            ->get(['story_a', 'story_b'])
            ->map(fn ($v) => [(int) $v->story_a, (int) $v->story_b])
            ->all();
    }

    private function confirmWithModel(array $candidates, array $stories): array
    {
        if ($candidates === []) {
            return [];
        }

        // NOT env(). Once the config is cached - and in production it always
        // is - env() returns null here, so this method returned [] before
        // asking anything, while the run still printed "confirmed by model:
        // 0 of 1163". It read like a measurement and was really a switched-off
        // step, four times an hour, for as long as the cache stayed warm.
        //
        // Ask the router instead: the same question the control panel answers,
        // and it does not care whether the config is cached. And say so out
        // loud, because the whole cost of this bug was its silence.
        $ai = \App\Services\Ai\AiRouter::for('dedupe');

        if (!$ai->isConfigured()) {
            $this->warn('  no provider configured for the dedupe task - no pair can be confirmed.');

            return [];
        }

        $confirmed = [];

        foreach ($candidates as $candidate) {
            [$a, $b] = $candidate;

            $question = "Two news items. Are they reporting THE SAME EVENT, so that showing both "
                . "would show a reader the same story twice?\n\n"
                . "Say NO unless one is a translation, a rewrite, or a wire copy of the other.\n"
                . "Two articles can cover one event and still be different stories, and both "
                . "belong in the feed: a crash report and the condolences for its victims, a "
                . "collision and a passenger's account of it, a verdict and the reaction to it. "
                . "Each tells a reader something the other does not.\n"
                . "Two air-quality readings taken at different hours are also separate.\n\n"
                . "A ({$stories[$a]['lang']}, {$stories[$a]['source']}): {$stories[$a]['title']}\n"
                . "{$stories[$a]['opening']}\n\n"
                . "B ({$stories[$b]['lang']}, {$stories[$b]['source']}): {$stories[$b]['title']}\n"
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

                // Anything short of an explicit yes leaves both stories alone.
                $same = is_array($verdict) && ($verdict['same'] ?? false) === true;

                if ($same) {
                    $confirmed[] = [$a, $b];
                }

                // A no is an answer too, and cost the same to get. Recording
                // it is what stops the next run buying it again.
                if (is_array($verdict)) {
                    DB::table('dedupe_verdicts')->updateOrInsert(
                        ['story_a' => min($a, $b), 'story_b' => max($a, $b)],
                        ['same' => $same, 'shared_tokens' => $candidate[2] ?? null,
                         'created_at' => now(), 'updated_at' => now()]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Dedupe confirmation failed', ['error' => $e->getMessage()]);
            }
        }

        return $confirmed;
    }

    // ── grouping and choosing ─────────────────────────────────────────────

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
                $parent[$ra] = $rb;
            }
        }

        $clusters = [];

        foreach ($pairs as [$a, $b]) {
            foreach ([$a, $b] as $x) {
                $clusters[$find($x)][$x] = true;
            }
        }

        return $clusters;
    }

    /**
     * Which copy to keep.
     *
     * The fullest text, because that is what the classifier reads and what the
     * reader's summary is written from - a wire stub and the paper that ran it
     * with three added paragraphs are the same story, and the longer one is
     * the better version of it. Ties go to whoever published first, which is
     * usually the originating wire.
     */
    private function pickKeeper(array $ids, array $stories): int
    {
        usort($ids, function ($x, $y) use ($stories) {
            // A story already judged wins, whatever its length: it has been
            // paid for, it may be on the site, and readers may have opened it.
            // Replacing it with an unjudged copy would spend money to change
            // nothing a reader can see.
            // A COPY READERS CAN ACTUALLY SEE OUTRANKS EVERYTHING.
            //
            // "Judged" was the first test and it is not enough: a story can be
            // judged and still never reach the feed, because it failed to
            // geocode or was held for a person. Choosing that one as the keeper
            // marks the ONE copy that did reach readers as a duplicate - so the
            // story is served by a row recorded as redundant, and any future
            // tidy-up of duplicates would take it off the site. Measured on
            // this table: 39 stories sitting in exactly that state.
            //
            // At ingest neither copy is served, both are false, and this falls
            // through to the order below unchanged. It decides only the late
            // merges, which are the ones that were wrong.
            return [$stories[$y]['served'], $stories[$y]['judged'],
                    $stories[$y]['length'], $stories[$x]['published']]
                <=> [$stories[$x]['served'], $stories[$x]['judged'],
                     $stories[$x]['length'], $stories[$y]['published']];
        });

        return $ids[0];
    }

    // ── small helpers ─────────────────────────────────────────────────────

    /** The fetcher's normalisation, so both stages agree on what a title is. */
    private function titleKey(string $title): string
    {
        $text = mb_strtolower($title);
        $text = preg_replace('/\s+[-|]\s+[^-|]{2,40}$/u', '', $text);
        $text = preg_replace('/^#?[a-z]+\s*:\s*/u', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    private function titleSimilarity(string $a, string $b): float
    {
        $a = $this->titleKey($a);
        $b = $this->titleKey($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    private function longWords(string $title): array
    {
        $words = preg_split('/\s+/u', $this->titleKey($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Han runs together without spaces, so bucket it on character pairs.
        if (preg_match('/\p{Han}/u', $title)) {
            $chars = preg_split('//u', preg_replace('/\P{Han}/u', '', $title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $grams = [];

            for ($i = 0; $i + 1 < count($chars); $i++) {
                $grams[] = $chars[$i] . $chars[$i + 1];
            }

            return array_slice(array_unique($grams), 0, 12);
        }

        return array_slice(array_values(array_unique(
            array_filter($words, fn ($w) => mb_strlen($w) > 4)
        )), 0, 12);
    }

    private function detectLanguage(string $text): string
    {
        $sample = mb_substr($text, 0, 600);

        if (preg_match('/\p{Han}/u', $sample)) {
            return 'zh';
        }

        if (preg_match('/\p{Tamil}/u', $sample)) {
            return 'ta';
        }

        $words = preg_split('/[^a-z]+/u', mb_strtolower($sample), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $ms = ['yang', 'dan', 'untuk', 'dengan', 'tidak', 'kepada', 'dalam', 'akan',
               'pada', 'ini', 'itu', 'adalah', 'daripada', 'telah', 'oleh', 'juga',
               'beliau', 'katanya', 'selepas', 'kerana', 'orang', 'di'];
        $en = ['the', 'and', 'for', 'with', 'that', 'from', 'was', 'were', 'has',
               'have', 'said', 'will', 'been', 'their', 'after', 'which', 'they'];

        $msHits = count(array_intersect($words, $ms));
        $enHits = count(array_intersect($words, $en));

        if ($msHits === 0 && $enHits === 0) {
            return 'unknown';
        }

        return $msHits > $enHits ? 'ms' : 'en';
    }

    /**
     * The vocabulary of an event: its numbers and its names.
     *
     * Numbers keep their decimals, because 6.14 identifies a story and 6 does
     * not, and a year identifies a day rather than an event. Names are the
     * capitalised words - Butterworth, Teluk Bahang, Yeoh - and they are what
     * makes this work between languages as well as within one, since a name
     * survives translation when no other word does.
     */
    private function tokensIn(string $text): array
    {
        $out = [];

        preg_match_all('/\d[\d.,]{1,}/u', $text, $numbers);

        foreach ($numbers[0] as $n) {
            $n = rtrim($n, '.,');

            if (mb_strlen($n) >= 2 && !preg_match('/^(19|20)\d\d$/', $n)) {
                $out['#' . $n] = true;
            }
        }

        preg_match_all('/\b\p{Lu}[\p{L}]{3,}\b/u', $text, $names);

        foreach ($names[0] as $w) {
            $out[mb_strtolower($w)] = true;
        }

        return array_keys($out);
    }
}

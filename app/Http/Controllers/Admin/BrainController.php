<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Adapters;
use App\Services\Ai\AiSpend;
use App\Services\Knowledge\Briefing;
use App\Services\Knowledge\CorrectionLog;
use App\Services\Knowledge\Playbook;
use App\Services\Knowledge\PromptAssembler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The resource centre: everything the AI is told, and everything that says
 * whether it is getting it right.
 *
 * One section rather than six scattered pages, because the parts only make
 * sense together. A rule is worth writing when the correction log says the same
 * mistake keeps happening; a playbook edit is worth keeping when the bench says
 * it scored better afterwards. Split across the panel they would each look like
 * a form nobody has a reason to fill in.
 */
class BrainController extends Controller
{
    public function __construct(
        private Playbook $playbook = new Playbook(),
        private Briefing $briefing = new Briefing(),
        private CorrectionLog $corrections = new CorrectionLog(),
    ) {
    }

    /** What the AI knows, at a glance, and what is missing. */
    public function index(): View
    {
        $parts = (new PromptAssembler())->parts('A headline', 'Body text.', 'A source');

        $sizes = [];

        foreach ($parts as $part) {
            if ($part['origin'] === 'article') {
                continue;
            }

            $sizes[$part['origin']] = ($sizes[$part['origin']] ?? 0) + strlen($part['text']);
        }

        $lastRun = DB::table('bench_runs')->orderByDesc('id')->first();

        return view('admin.brain.index', [
            'sizes'        => $sizes,
            'total'        => array_sum($sizes),
            'benchItems'   => DB::table('bench_items')->count(),
            'lastRun'      => $lastRun,
            'lastScores'   => $lastRun && $lastRun->scores ? json_decode($lastRun->scores, true) : null,
            'corrections'  => DB::table('corrections')->count(),
            'tally'        => $this->corrections->tally(),
            'terms'        => DB::table('briefing_terms')->where('is_active', true)->count(),
            'sections'     => count($this->playbook->sections()),
            'adapters'     => Adapters::all(),

            // The parts that were teaching the AI all along, from elsewhere in
            // the panel. Counted here so the overview shows what the model
            // knows rather than only what was built most recently.
            'rules'        => DB::table('review_rules')->where('is_active', true)->count(),
            'casesLive'    => DB::table('news_items')->where('origin', 'editorial')
                                ->where('review_status', 'published')->count(),
            'casesDraft'   => DB::table('news_items')->where('origin', 'editorial')
                                ->where('review_status', '!=', 'published')->count(),
            'sourcesOn'    => DB::table('sources')->where('is_active', true)->count(),
            'sourcesNoted' => DB::table('sources')->where('is_active', true)
                                ->whereNotNull('expect_note')->count(),
            'removals'     => DB::table('removals')->count(),
            'proposals'    => DB::table('bench_items')->where('proposed', true)->count(),

            // Stage one, in one line: is the pipeline reading articles, or
            // headlines? Everything downstream depends on the answer, so it
            // belongs on the overview rather than three pages in.
            'readFull'     => $this->readingCounts()['full'],
            'readTeaser'   => $this->readingCounts()['teaser'],
            'readHeld'     => $this->readingCounts()['held'],
        ]);
    }

    /**
     * How much of an article the pipeline is actually holding.
     *
     * 'full'   - enough to summarise and locate from.
     * 'teaser' - a paywalled or blocked publisher's own opening, published as
     *            written and never expanded.
     * 'held'   - nothing usable; served to nobody.
     */
    /**
     * The three buckets in ONE pass, remembered for five minutes. Three separate
     * lateral counts over an unindexed extraction_jobs took nine minutes each on
     * 3 Sep 2026 and, reloaded a few times, filled every web worker (outage).
     * The index (news_item_id, id desc) exists now; the cache keeps the page
     * from paying even the fast version on every reload.
     */
    private function readingCounts(): array
    {
        return cache()->remember('brain.reading-counts', 300, function () {
            $row = DB::selectOne("
                with latest as (
                    select distinct on (x.news_item_id) x.news_item_id, x.extraction_status, length(x.extracted_text) as len
                    from extraction_jobs x
                    join news_items n on n.id = x.news_item_id
                    where n.published_at > now() - interval '7 days' and n.url not like '%news.google%'
                    order by x.news_item_id, x.id desc
                )
                select
                    count(*) filter (where extraction_status in ('success','fallback_used') and len >= 600) as full,
                    count(*) filter (where extraction_status in ('success','fallback_used') and coalesce(len, 0) < 600) as teaser,
                    count(*) filter (where extraction_status not in ('success','fallback_used')) as held
                from latest");

            return ['full' => (int) ($row->full ?? 0), 'teaser' => (int) ($row->teaser ?? 0), 'held' => (int) ($row->held ?? 0)];
        });
    }

    private function reading(string $bucket): int
    {
        $sql = "
            select count(*) as n
            from news_items n
            join lateral (
                select * from extraction_jobs x
                where x.news_item_id = n.id order by x.id desc limit 1
            ) e on true
            where n.published_at > now() - interval '7 days'
              and n.url not like '%news.google%'
              and ";

        $sql .= match ($bucket) {
            'full'   => "e.extraction_status in ('success','fallback_used') and length(e.extracted_text) >= 600",
            'teaser' => "e.extraction_status in ('success','fallback_used') and length(e.extracted_text) < 600",
            default  => "e.extraction_status not in ('success','fallback_used')",
        };

        return (int) (DB::selectOne($sql)->n ?? 0);
    }

    /**
     * The prompt, exactly as the model receives it.
     *
     * Built by the same assembler the pipeline uses, not rebuilt for display: a
     * viewer that reconstructs the text its own way shows you something the
     * model never saw, and drifts further from the truth every month.
     */
    public function prompt(Request $request): View
    {
        $newsItemId = (int) $request->query('news_item_id');

        $story = $newsItemId
            ? DB::table('news_items as n')
                ->leftJoin('extraction_jobs as e', function ($join) {
                    $join->on('e.news_item_id', '=', 'n.id')
                         ->whereIn('e.extraction_status', ['success', 'fallback_used']);
                })
                ->where('n.id', $newsItemId)
                ->orderByDesc('e.id')
                ->select('n.id', 'n.title', 'n.source', 'e.extracted_text')
                ->first()
            : null;

        $parts = (new PromptAssembler())->parts(
            $story->title ?? 'Example: Fire guts furniture factory in Bercham',
            $story->extracted_text ?? 'A fire destroyed a furniture factory in Bercham, Ipoh on Sunday night. No injuries were reported.',
            $story->source ?? 'The Star'
        );

        // Which door: a gathered story, or a reader's post. Both end up in the
        // same classifier; only the check at the door differs.
        $for = $request->query('for') === 'contributor' ? 'contributor' : 'scraper';

        return view('admin.brain.prompt', [
            'for'               => $for,
            'contributorPrompt' => $for === 'contributor'
                ? (new \App\Services\Contribution\NewsworthinessReview())->previewPrompt()
                : '',
            'parts'   => $parts,
            'origins' => PromptAssembler::ORIGINS,
            'story'   => $story,
            'recent'  => DB::table('news_items')
                ->whereNotNull('ai_processed_at')
                ->orderByDesc('ai_processed_at')
                ->limit(12)
                ->get(['id', 'title']),
        ]);
    }

    /**
     * What each publisher permits, exposes, and costs us.
     *
     * Blocked and broken first: those are the rows that need a decision, and
     * putting them under forty working sources is how they stay unmade.
     */
    public function constraints(): View
    {
        $sources = DB::table('sources')
            ->whereNull('parent_source_id')
            ->orderByRaw("case robots_policy when 'prohibited' then 0 when 'ai_restricted' then 1 else 2 end")
            ->orderByRaw('case when best_route is null then 0 else 1 end')
            ->orderByRaw('coalesce(coverage_pct, 100)')
            ->orderBy('name')
            ->get();

        return view('admin.brain.constraints', [
            'sources'    => $sources,
            'unaudited'  => $sources->whereNull('audited_at')->count(),
            'summary'    => $sources->whereNotNull('audited_at')
                                ->groupBy('robots_policy')
                                ->map->count()
                                ->sortKeys()
                                ->all(),
            'labels'     => [
                'permitted'     => 'permitted',
                'ai_restricted' => 'AI restricted',
                'prohibited'    => 'prohibited',
                'unknown'       => 'unknown',
            ],
            'blurbs'     => [
                'permitted'     => 'Nothing in their robots.txt stands against reading them.',
                'ai_restricted' => 'They block AI crawlers by name. General reading is permitted, but the intent is plain and worth a licensing conversation.',
                'prohibited'    => 'They forbid automated extraction in writing. Written permission is the only route.',
                'unknown'       => 'No robots.txt was served, so nothing can be assumed either way.',
            ],
        ]);
    }

    /**
     * What each source delivered, day by day.
     *
     * A source that stops is silent: the feed keeps filling from everything
     * else and the site looks fine. So the report leads with what has gone
     * quiet rather than with the total, which is the number that hides it.
     */
    public function daily(): View
    {
        $days = [];

        for ($i = 13; $i >= 0; $i--) {
            $days[] = now()->subDays($i)->toDateString();
        }

        // A publisher's sections count as that publisher delivering.
        $stats = DB::table('source_daily_stats as s')
            ->join('sources as src', 'src.id', '=', 's.source_id')
            ->where('s.day', '>=', $days[0])
            ->selectRaw("coalesce(src.parent_source_id, src.id) as root_id,
                         to_char(s.day, 'YYYY-MM-DD') as day,
                         sum(s.items_new) as n")
            ->groupBy('root_id', 'day')
            ->get();

        $roots = DB::table('sources')
            ->whereNull('parent_source_id')
            ->get(['id', 'name', 'is_active', 'last_fetched_at'])
            ->keyBy('id');

        $rows = [];
        $totals = [];

        foreach ($stats as $stat) {
            $id = (int) $stat->root_id;

            if (!isset($roots[$id])) {
                continue;
            }

            $rows[$id] ??= [
                'id'        => $id,
                'name'      => $roots[$id]->name,
                'is_active' => $roots[$id]->is_active,
                'by_day'    => [],
                'total'     => 0,
                'quiet'     => false,
            ];

            $rows[$id]['by_day'][$stat->day] = (int) $stat->n;
            $rows[$id]['total'] += (int) $stat->n;
            $totals[$stat->day] = ($totals[$stat->day] ?? 0) + (int) $stat->n;
        }

        // Delivered nothing today, having averaged something over the week.
        $today = now()->toDateString();
        $quiet = [];

        foreach ($rows as $id => $row) {
            $week = 0;

            foreach (array_slice($days, -8, 7) as $d) {
                $week += $row['by_day'][$d] ?? 0;
            }

            $perDay = (int) round($week / 7);

            if (($row['by_day'][$today] ?? 0) === 0 && $perDay > 0 && $roots[$id]->is_active) {
                $rows[$id]['quiet'] = true;
                $quiet[] = (object) [
                    'id'              => $id,
                    'name'            => $row['name'],
                    'per_day'         => $perDay,
                    'last_fetched_at' => $roots[$id]->last_fetched_at,
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return view('admin.brain.daily', [
            'days'   => $days,
            'rows'   => $rows,
            'totals' => $totals,
            'quiet'  => collect($quiet)->sortByDesc('per_day')->values(),
        ]);
    }

    /**
     * What is left to spend, and what it went on.
     */
    public function spend(AiSpend $spend): View|RedirectResponse
    {
        // merged into the AI models page (owner, 4 Sep 2026)
        return redirect()->route('admin.brain.ai');
        $balance = $spend->balance();

        $cached = DB::selectOne("
            select coalesce(sum(cache_hit_tokens), 0) as hit,
                   coalesce(sum(cache_miss_tokens), 0) as miss
            from ai_processing_jobs
            where created_at >= now() - interval '7 days'
        ");

        $total = (int) $cached->hit + (int) $cached->miss;

        return view('admin.brain.spend', [
            'balance'     => $balance,
            'daysLeft'    => $spend->daysLeft($balance['balance']),
            'daily'       => $spend->daily(14),
            'cachedPct'   => $total > 0 ? (int) round($cached->hit * 100 / $total) : null,
            'cachedDays'  => 7,
            'ratesReadOn' => AiSpend::RATES_READ_ON,
        ]);
    }

    /** Ask the provider again rather than trusting the cached reading. */
    public function refreshBalance(AiSpend $spend): RedirectResponse
    {
        $balance = $spend->balance(true);

        return back()->with('status', $balance['error']
            ?? 'Checked: ' . number_format((float) $balance['balance'], 2) . ' ' . $balance['currency'] . ' remaining.');
    }

    // ── The playbook ──────────────────────────────────────────────────────

    public function playbook(): View
    {
        $country = \App\Support\BrainCountry::iso2();

        return view('admin.brain.playbook', [
            'sections'  => $this->playbook->forCountry($country)->sections(),
            'country'   => $country,
            'countryName' => \App\Support\BrainCountry::name($country),
            'revisions' => DB::table('playbook_revisions')
                ->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function savePlaybook(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $country = \App\Support\BrainCountry::iso2();
        $this->playbook->forCountry($country)->save($key, $data['body'], $data['note'] ?? null, Auth::guard('admin')->id());

        return back()->with('status', $country === null
            ? 'Saved. The next story judged will use it.'
            : 'Saved for ' . \App\Support\BrainCountry::name($country) . '. Stories from its publishers use this text; every other country keeps the base.');
    }

    public function revertPlaybook(string $key): RedirectResponse
    {
        $country = \App\Support\BrainCountry::iso2();

        if ($country !== null) {
            $this->playbook->forCountry($country)->revert($key);
        }

        return back()->with('status', 'Back to the base text for ' . \App\Support\BrainCountry::name($country) . '.');
    }

    public function togglePlaybook(string $key): RedirectResponse
    {
        $country = \App\Support\BrainCountry::iso2();
        $section = $this->playbook->forCountry($country)->sections()[$key] ?? null;

        if (!$section) {
            return back();
        }

        $this->playbook->forCountry($country)->setActive($key, !$section['is_active']);

        return back()->with('status', $section['is_active']
            ? 'Switched off. The model will no longer be told this.'
            : 'Switched back on.');
    }

    // ── The Malaysia briefing ─────────────────────────────────────────────

    public function briefing(): View
    {
        // the country switch beside Overview decides whose terms are shown and added
        $iso2 = \App\Support\BrainCountry::iso2();
        $terms = DB::table('briefing_terms')
            ->when($iso2, fn ($q) => $q->where(fn ($w) => $w->whereNull('country')->orWhere('country', $iso2)))
            ->orderBy('kind')->orderBy('term')->get();

        return view('admin.brain.briefing', [
            'terms'   => $terms,
            'kinds'   => Briefing::KINDS,
            'sent'    => count($this->briefing->active($iso2)),
            'country' => $iso2,
            'countryName' => $iso2 ? \App\Support\BrainCountry::name($iso2) : null,
        ]);
    }

    public function addTerm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'term'        => ['required', 'string', 'max:80'],
            'kind'        => ['required', 'string', 'max:20'],
            'expansion'   => ['required', 'string', 'max:200'],
            'implication' => ['nullable', 'string', 'max:600'],
        ]);

        // added under the country the switch is on; under "All countries" it is told to every country
        $country = \App\Support\BrainCountry::iso2();

        DB::table('briefing_terms')->updateOrInsert(
            ['term' => $data['term'], 'kind' => $data['kind'], 'country' => $country],
            [
                'expansion'   => $data['expansion'],
                'implication' => $data['implication'] ?? null,
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        $this->briefing->bumpVersion();

        return back()->with('status', empty($data['implication'])
            ? 'Saved, but with no implication it will not be sent to the model. Say what it means for the answer.'
            : 'Saved. The model will be told from the next story.');
    }

    public function updateTerm(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'expansion'   => ['required', 'string', 'max:200'],
            'implication' => ['nullable', 'string', 'max:600'],
            'is_active'   => ['nullable'],
        ]);

        DB::table('briefing_terms')->where('id', $id)->update([
            'expansion'   => $data['expansion'],
            'implication' => $data['implication'] ?? null,
            'is_active'   => $request->boolean('is_active'),
            'updated_at'  => now(),
        ]);

        $this->briefing->bumpVersion();

        return back()->with('status', 'Saved.');
    }

    public function deleteTerm(int $id): RedirectResponse
    {
        DB::table('briefing_terms')->where('id', $id)->delete();
        $this->briefing->bumpVersion();

        return back()->with('status', 'Removed.');
    }

    // ── The bench ─────────────────────────────────────────────────────────

    public function bench(Request $request): View
    {
        $runs = DB::table('bench_runs')->orderByDesc('id')->limit(10)->get();

        // Newest first is right nearly always - you judge what just came in.
        // Oldest first exists for the other case: clearing a backlog from the
        // end that has been waiting longest.
        $oldestFirst = $request->query('sort') === 'oldest';

        // ⛔ THE LIST SHOWED THE NEWEST 60 OF 355 AND NOTHING ELSE.
        //
        // Owner, 4 Sep 2026, twice: "where is the 108 and 218 news? i cant see
        // it!" He could not, and neither could anyone: ids 352-411 were the
        // only rows this page had ever rendered, with no search, no paging and
        // no hint that 295 answers existed below the fold. An answer you cannot
        // reach is an answer you cannot correct, and a wrong one marks the
        // model down for being right - which is what the text above this table
        // warns about.
        $find = trim((string) $request->query('q', ''));

        // "answers that can never match": expect_category holds free text, and
        // a note typed into it can never equal a category, so the row is a
        // permanent failure. Two were found this way.
        $broken = $request->query('show') === 'broken';

        return view('admin.brain.bench', [
            'find'   => $find,
            'broken' => $broken,
            'brokenCount' => DB::table('bench_items')->where('proposed', false)
                ->whereNotNull('expect_category')
                ->whereRaw('lower(expect_category) not in (select lower(name) from categories)')
                ->count(),
            'items' => DB::table('bench_items as b')
                ->where('b.proposed', false)
                ->when($broken, fn ($q) => $q->whereNotNull('b.expect_category')
                    ->whereRaw('lower(b.expect_category) not in (select lower(name) from categories)'))
                ->when($find !== '', function ($q) use ($find) {
                    // a number is an id, anything else is words in the title
                    if (ctype_digit($find)) {
                        $q->where('b.id', (int) $find);

                        return;
                    }

                    $q->where('b.title', 'ilike', '%' . $find . '%');
                })
                ->orderByDesc('b.id')
                ->limit($find !== '' || $broken ? 200 : 60)
                ->get([
                    'b.*',
                    // Whether the story is still being served. An answer of
                    // "should not have been published" beside a story that is
                    // live is the thing this page exists to surface.
                    DB::raw('exists (select 1 from feed_ready_items f
                        where f.news_item_id = b.news_item_id and f.is_active) as is_live'),
                ]),
            'count' => DB::table('bench_items')->where('proposed', false)->count(),
            // The link and the read text, same as the rows above: a proposal
            // asks you to agree with a judgement, so it has to show what the
            // judgement was made from.
            'proposals' => DB::table('bench_items as b')
                ->leftJoin('news_items as n', 'n.id', '=', 'b.news_item_id')
                ->leftJoin(DB::raw('lateral (
                    select extracted_text from extraction_jobs x
                    where x.news_item_id = b.news_item_id
                      and x.extraction_status in (\'success\',\'fallback_used\')
                    order by x.id desc limit 1
                ) e'), DB::raw('true'), DB::raw('true'))
                ->where('b.proposed', true)
                ->orderBy('b.expect_keep')
                ->orderBy('b.id')
                ->get([
                    'b.*', 'n.url', 'n.source',
                    DB::raw('left(regexp_replace(coalesce(e.extracted_text, \'\'), \'\s+\', \' \', \'g\'), 260) as read_text'),
                    DB::raw('length(e.extracted_text) as read_chars'),
                ]),
            'runs'  => $runs->map(function ($run) {
                $run->parsed = $run->scores ? json_decode($run->scores, true) : null;

                return $run;
            }),
            // Recent stories with what the AI decided, so confirming an answer
            // is one click. Most answers are right; the bench fills fastest by
            // agreeing quickly and stopping to correct only what is wrong.
            // The link and the text the model was given. An answer can only be
            // judged against what the model read, and a headline does not say
            // whether the article named a place.
            'recent' => DB::table('news_items as n')
                ->leftJoin('bench_items as b', 'b.news_item_id', '=', 'n.id')
                ->leftJoin(DB::raw('lateral (
                    select extracted_text from extraction_jobs x
                    where x.news_item_id = n.id
                      and x.extraction_status in (\'success\',\'fallback_used\')
                    order by x.id desc limit 1
                ) e'), DB::raw('true'), DB::raw('true'))
                ->whereNotNull('n.ai_processed_at')
                ->whereNull('b.id')
                ->orderBy('n.ai_processed_at', $oldestFirst ? 'asc' : 'desc')
                ->limit(25)
                ->get([
                    'n.id', 'n.title', 'n.discarded', 'n.ai_category',
                    'n.sub_category', 'n.main_place_text', 'n.url', 'n.source',
                    'n.ai_processed_at',
                    DB::raw('left(regexp_replace(coalesce(e.extracted_text, \'\'), \'\s+\', \' \', \'g\'), 320) as read_text'),
                    DB::raw('length(e.extracted_text) as read_chars'),
                    // Whether the story is still being served. addSelect()
                    // cannot be used here: it sets the column list, and get()
                    // then leaves it alone, so every other field vanishes.
                    DB::raw('exists (select 1 from feed_ready_items f
                        where f.news_item_id = n.id and f.is_active) as is_live'),
                ]),
            'fields' => CorrectionLog::FIELDS,
            'oldestFirst' => $oldestFirst,
        ]);
    }

    /** Confirm the current answer as correct, exactly as it stands. */
    public function confirm(Request $request): RedirectResponse
    {
        $id = (int) $request->input('news_item_id');
        $story = DB::table('news_items')->where('id', $id)->first();

        if (!$story) {
            return back()->withErrors(['news_item_id' => 'No such story.']);
        }

        DB::table('bench_items')->updateOrInsert(
            ['news_item_id' => $id],
            [
                'title'           => mb_substr((string) $story->title, 0, 500),
                'expect_keep'     => !$story->discarded,
                'expect_category' => $story->ai_category,
                'expect_sub'      => $story->sub_category,
                'expect_place'    => $story->main_place_text ?: null,
                'expect_nowhere'  => empty($story->main_place_text),
                'note'            => 'Confirmed as correct',
                'confirmed_by'    => Auth::guard('admin')->id(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]
        );

        return back()->with('status', 'Added to the bench as a correct answer.');
    }

    /**
     * Change an answer already on the bench.
     *
     * A wrong answer here is worse than no bench at all: it marks the model
     * down for being right, and the numbers then argue against the change that
     * actually helped.
     */
    public function updateBenchItem(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'expect_category' => ['nullable', 'string', 'max:60'],
            'expect_place'    => ['nullable', 'string', 'max:200'],
            'note'            => ['nullable', 'string', 'max:2000'],
        ]);

        $nowhere = $request->boolean('expect_nowhere');

        DB::table('bench_items')->where('id', $id)->update([
            'expect_keep'     => $request->boolean('expect_keep'),
            'expect_category' => $data['expect_category'] ?: null,
            // A place and "the answer is nowhere" are contradictory claims, so
            // the checkbox wins and the text is cleared rather than left to sit
            // there looking like it still means something.
            'expect_place'    => $nowhere ? null : ($data['expect_place'] ?: null),
            'expect_nowhere'  => $nowhere,
            'note'            => $data['note'] ?: null,
            'updated_at'      => now(),
        ]);

        return back()->with('status', 'Changed. The next bench run will hold the model to this.');
    }

    /**
     * Accept a proposed answer, which is what turns it into an answer.
     *
     * Until this happens the row is excluded from every run, so a proposal that
     * is never looked at costs nothing and changes nothing - which is the point
     * of keeping the two apart.
     */
    /**
     * Which proposals an action applies to.
     *
     * One row (a per-row button), the ticked ones, or every proposal. Returned
     * as null for "all", which the caller turns into an unfiltered query -
     * distinct from an empty list, which means nothing was ticked and nothing
     * should happen.
     *
     * @return list<int>|null
     */
    private function chosen(Request $request): ?array
    {
        if ($request->filled('only')) {
            return [(int) $request->input('only')];
        }

        if ($request->boolean('all')) {
            return null;
        }

        return array_map('intval', (array) $request->input('ids', []));
    }

    /**
     * Accept proposals, which is what turns them into answers.
     *
     * Until this happens they are excluded from every run, so a proposal nobody
     * looks at costs nothing and changes nothing.
     */
    public function acceptProposal(Request $request): RedirectResponse
    {
        $ids = $this->chosen($request);

        if ($ids === []) {
            return back()->with('status', 'Nothing was ticked, so nothing was accepted.');
        }

        $query = DB::table('bench_items')->where('proposed', true);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $accepted = $query->update([
            'proposed'     => false,
            'note'         => DB::raw("COALESCE(proposed_reason, 'Accepted from review')"),
            'confirmed_by' => Auth::guard('admin')->id(),
            'updated_at'   => now(),
        ]);

        return back()->with('status', $accepted === 1
            ? 'Accepted. The bench will hold the model to it from the next run.'
            : "Accepted {$accepted} answers.");
    }

    public function rejectProposal(Request $request): RedirectResponse
    {
        $ids = $this->chosen($request);

        if ($ids === []) {
            return back()->with('status', 'Nothing was ticked, so nothing was thrown away.');
        }

        $query = DB::table('bench_items')->where('proposed', true);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $thrown = $query->delete();

        return back()->with('status', $thrown === 1
            ? 'Thrown away. Nothing was added to the bench.'
            : "Thrown away {$thrown} proposals. Nothing was added to the bench.");
    }

    public function removeBenchItem(int $id): RedirectResponse
    {
        DB::table('bench_items')->where('id', $id)->delete();

        return back()->with('status', 'Removed from the bench.');
    }

    // ── Corrections ───────────────────────────────────────────────────────

    /**
     * The three versions of each story, for reading across.
     *
     * Grouped in PHP rather than joined three times in SQL: the query stays
     * one page of stories and one pass over their translations, and a story
     * missing a language still appears - which is the case worth seeing, and
     * the one an inner join would have hidden.
     */
    public function translations(Request $request): View
    {
        $lang = in_array($request->query('lang'), ['en', 'ms', 'zh'], true)
            ? $request->query('lang')
            : 'all';

        // A day at a time. Nobody audits translations in publication order
        // across months - they check today's, or the day something looked
        // wrong. It also bounds the query, which matters as the table grows.
        $translated = fn ($q) => $q->select(DB::raw(1))
            ->from('news_translations as t')->whereColumn('t.news_item_id', 'news_items.id');

        // Stored in UTC, read in Malaysia: the day boundary has to be the
        // reader's, or stories published in the evening land on yesterday.
        $klDate = "(published_at at time zone 'UTC' at time zone 'Asia/Kuala_Lumpur')::date";

        $days = DB::table('news_items')
            ->whereExists($translated)
            ->selectRaw("{$klDate} as day, count(*) as n")
            ->groupBy(DB::raw($klDate))
            ->orderByDesc(DB::raw($klDate))
            ->limit(14)
            ->get();

        $day = $request->query('day');

        if (!$day || !$days->contains('day', $day)) {
            $day = $days->first()->day ?? now()->timezone('Asia/Kuala_Lumpur')->toDateString();
        }

        $stories = DB::table('news_items')
            ->whereExists($translated)
            ->whereRaw("{$klDate} = ?", [$day])
            ->when($lang !== 'all', fn ($q) => $q->where('source_language', $lang))
            ->orderByDesc('published_at')
            ->get(['id', 'title', 'source', 'published_at', 'ai_category', 'source_language']);

        $versions = DB::table('news_translations')
            ->whereIn('news_item_id', $stories->pluck('id'))
            ->get(['news_item_id', 'locale', 'title', 'summary'])
            ->groupBy('news_item_id');

        foreach ($stories as $story) {
            $story->versions = ($versions[$story->id] ?? collect())->keyBy('locale');
        }

        return view('admin.brain.translations', [
            'stories'   => $stories,
            'days'      => $days,
            'day'       => $day,
            'lang'      => $lang,
            'languages' => DB::table('news_items')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('news_translations as t')->whereColumn('t.news_item_id', 'news_items.id'))
                ->whereNotNull('source_language')
                ->selectRaw('source_language, count(*) as n')
                ->groupBy('source_language')
                ->orderByDesc('n')
                ->pluck('n', 'source_language'),
        ]);
    }

    /**
     * A day at a time: what arrived, what went live, and where the rest went.
     *
     * Every other page here judges one story. This one judges a day. A run that
     * fetched nothing, a classifier that stopped, a morning where four fifths of
     * the feed was thrown away - none of those show up in a list of stories,
     * because the evidence is a number that did not appear.
     *
     * Three things make the numbers trustworthy enough to act on:
     *
     * The buckets are mutually exclusive, in priority order - live, then
     * duplicate, then discarded, then waiting - so the row adds up to what
     * arrived. Counted independently they overlapped by fifty stories, and five
     * numbers that do not agree cannot tell you anything. Whatever the four
     * buckets do not claim is shown as "held", because a story that arrived,
     * was not published, and was not refused for any stated reason is the most
     * interesting row on the page.
     *
     * The day is the Malaysian day. Times are stored in UTC, so a story filed
     * at 2am in Kuala Lumpur is stored at 6pm the day before; grouped naively,
     * everything between midnight and 8am is filed under yesterday.
     *
     * And every count is a link. A number nobody can open is a number nobody
     * can check - the drill-down reads its rows through the SAME bucket
     * definitions, in bucketPredicate(), so clicking 531 cannot return 528.
     */
    public function live(Request $request): View
    {
        $days = max(7, min(120, (int) $request->query('days', 30)));
        // The country a story claims (the boundary layer stamps every story,
        // held or not). Three capitals or nothing, so it can sit in the SQL.
        $country    = $this->liveCountry($request);
        $countrySql = $country === 'ALL' ? '' : "and n.geo_claim_country = '{$country}'";

        // One pass over each side table, joined once - not three correlated
        // subqueries per story. Neither feed_ready_items nor ai_processing_jobs
        // had an index on news_item_id, so each of those subqueries was a full
        // scan and this page took eight and a half seconds. See the migration
        // 2026_09_02_140000_index_feed_lookups.
        $rows = DB::select("
            with feed as (
                select news_item_id,
                       count(*)                                                  as pins,
                       count(*) filter (where lat is not null and lng is not null) as placed,
                       count(*) filter (where relevance_mode = 'category_only')  as national
                from feed_ready_items
                where is_active
                group by news_item_id
            ),
            -- ⛔ NO LONGER USED FOR MONEY. See the note below the query: the
            -- cost and call columns now come from ai_call_log by the day the
            -- call was made, because that is what was actually spent.
            spend as (
                select news_item_id, 0::numeric as cost, 0::bigint as calls
                from ai_processing_jobs
                group by news_item_id
            ),
            per_story as (
                select
                    {$this->klDay('n.published_at')} as day,
                    n.ai_status,
                    n.discarded,
                    coalesce(f.pins, 0)     as pins,
                    coalesce(f.placed, 0)   as placed,
                    coalesce(f.national, 0) as national,
                    coalesce(s.cost, 0)     as cost,
                    coalesce(s.calls, 0)    as calls
                from news_items n
                left join feed  f on f.news_item_id = n.id
                left join spend s on s.news_item_id = n.id
                where n.published_at >= now() - (? || ' days')::interval
                  {$countrySql}
            )
            select
                day,
                count(*)                                                     as ingested,
                count(*) filter (where pins > 0)                             as live,
                count(*) filter (where pins > 0 and placed = 1)              as one_place,
                count(*) filter (where pins > 0 and placed > 1)              as many_places,
                count(*) filter (where pins > 0 and placed = 0
                                   and national > 0)                         as national,
                count(*) filter (where pins > 0 and placed = 0
                                   and national = 0)                         as no_place,
                count(*) filter (where pins = 0
                                   and ai_status = 'duplicate')              as duplicate,
                count(*) filter (where pins = 0
                                   and ai_status is distinct from 'duplicate'
                                   and (discarded or ai_status = 'discarded')) as discarded,
                count(*) filter (where pins = 0
                                   and ai_status = 'pending')                as waiting,
                round(sum(cost)::numeric, 4)                                 as cost,
                sum(calls)                                                   as calls
            from per_story
            group by day
            order by day desc
        ", [$days]);

        // ⛔⛔ THE MONEY IS WHAT WAS SPENT THAT DAY, NOT WHAT THIS DAY'S NEWS COST.
        //
        // This column used to sum estimated_cost from ai_processing_jobs joined
        // per story, and attribute it to the story's PUBLISHED day. Two things
        // were wrong with that, and the owner spotted both:
        //
        //  1. It counted only calls tied to a story. Dedupe confirmations,
        //     translation, retitling, place names, taxonomy and community
        //     moderation have no news_item_id, so none of that spending
        //     appeared anywhere on this page. On 5 Sep it showed 56 calls
        //     against 7,107 actually made.
        //
        //  2. Overnight fetches pull mostly YESTERDAY'S news, so the cost of
        //     work done today landed on yesterday's row. The column could never
        //     be reconciled against the bill.
        //
        // ai_call_log is the complete record - every task, every provider - and
        // grouping it by the Malaysian day the call was made is the same
        // question the AI models page answers, so the two now agree by
        // construction rather than by coincidence.
        $spend = DB::table('ai_call_log')
            ->where('created_at', '>=', now()->subDays($days + 1)->startOfDay())
            ->selectRaw("{$this->klDay('created_at')} as day,
                         count(*) as calls,
                         coalesce(sum(cost), 0) as cost")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // ⛔ ai_call_log only began on 4 Sep 2026, with the AI panel. Before
        // that the only record is ai_processing_jobs, which covers story
        // classification alone - so those days are an undercount of calls and,
        // because no cache split was stored then, an OVERSTATEMENT of cost.
        //
        // Used anyway, and marked: a day that plainly cost money reading
        // 0.0000 is worse than a day reading approximately. `estimated` says
        // which is which, and the page says what it means.
        $earliest = DB::table('ai_call_log')->min('created_at');

        $older = DB::table('ai_processing_jobs')
            ->where('created_at', '>=', now()->subDays($days + 1)->startOfDay())
            ->when($earliest !== null, fn ($q) => $q->where('created_at', '<', $earliest))
            ->selectRaw("{$this->klDay('created_at')} as day,
                         count(*) as calls,
                         coalesce(sum(estimated_cost), 0) as cost")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        foreach ($rows as $row) {
            // Not a sixth count - the remainder. Arrived, not published, and no
            // reason on file. Zero on a healthy day.
            $row->held = (int) $row->ingested
                - (int) $row->live - (int) $row->duplicate
                - (int) $row->discarded - (int) $row->waiting;

            $day = $spend[$row->day] ?? null;
            $row->estimated = false;

            if ($day === null && isset($older[$row->day])) {
                $day = $older[$row->day];
                $row->estimated = true;
            }

            $row->calls = (int) ($day->calls ?? 0);
            $row->cost  = round((float) ($day->cost ?? 0), 4);
        }

        $keys = ['ingested', 'live', 'one_place', 'many_places', 'national',
                 'no_place', 'duplicate', 'discarded', 'waiting', 'held', 'calls'];

        $total = [];

        foreach ($keys as $k) {
            $total[$k] = array_sum(array_column($rows, $k));
        }

        $total['cost'] = round(array_sum(array_map('floatval', array_column($rows, 'cost'))), 4);

        // A fetch run that died, or collected stories and stored none.
        //
        // ⛔ On 4 Sep 2026 the 08:00 run collected 574 stories, threw, and lost
        // every one. Nothing on this page changed except the day's total, which
        // simply read low - the same shape as a quiet morning. The owner asked
        // "is the php scrapper even working today?" and he was right to.
        //
        // Two hours, not twenty-four: the fetch runs every six, so a fault is
        // worth showing until the next run has had its chance to clear it.
        $badRuns = DB::table('fetch_runs')
            ->where('started_at', '>', now()->subHours(26))
            ->whereIn('status', ['crashed', 'barren'])
            ->orderByDesc('started_at')
            ->limit(6)
            ->get(['tier', 'started_at', 'collected', 'created', 'status', 'error']);

        return view('admin.brain.live', [
            'badRuns'   => $badRuns,
            'rows'      => $rows,
            'days'      => $days,
            'total'     => $total,
            'recon'     => $this->reconciliation(),
            'country'   => $country,
            'countries' => $this->liveCountries($days),
        ]);
    }

    /** ALL, or an ISO 3166 alpha-3 code the request asked for. */
    private function liveCountry(Request $request): string
    {
        $c = strtoupper(trim((string) $request->query('country', 'ALL')));

        return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'ALL';
    }

    /**
     * The countries stories claimed in the window, most first, for the chips.
     *
     * @return array<string, array{name: string, n: int}>
     */
    private function liveCountries(int $days): array
    {
        $out = [];

        foreach (DB::select("select geo_claim_country as c, count(*) as n from news_items
                             where published_at >= now() - (? || ' days')::interval and geo_claim_country is not null
                             group by 1 order by 2 desc", [$days]) as $r) {
            $out[$r->c] = ['name' => \App\Services\Geo\Boundaries\Iso3166::name($r->c) ?? $r->c, 'n' => (int) $r->n];
        }

        return $out;
    }

    /**
     * Estimate against reality - on the same basis, or not at all.
     *
     * The Cost column on each row is what classifying THAT DAY'S STORIES came
     * to. That is the useful figure for running the site: it is the cost of a
     * day's news, and it divides by that day's story count.
     *
     * It is not the figure to set against the bank. Money leaves the account on
     * the day the CALL RAN, and a backlog worked through late spends today's
     * money on last week's stories - here, 1,030 calls were attributable to the
     * 2nd's stories while 2,355 calls actually ran that day. Putting those two
     * numbers in neighbouring columns would invite the reader to subtract them
     * and conclude the estimate was 28% out, when they are answers to different
     * questions.
     *
     * So the check lives here, apart from the table, and both sides are counted
     * by the day the call ran:
     *
     *   estimated  tokens x DeepSeek's published rates, summed over the calls
     *              made that day
     *   actual     the fall in the real account balance across that day
     *
     * Two limits, stated on the page rather than buried: the balance is the
     * whole account, so anything else billed to it lands in the actual figure;
     * and a reading is a moment, so a day is only measurable when it is bounded
     * by a reading at each end. The cron now takes one at midnight Kuala
     * Lumpur, which is the boundary these days use - at 08:15 a reading
     * straddled two of them and confirmed nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function reconciliation(): array
    {
        $estimated = collect(DB::select("
            select {$this->klDay('created_at')} as day,
                   count(*)                          as calls,
                   round(sum(estimated_cost)::numeric, 4) as estimated
            from ai_processing_jobs
            group by day
        "))->keyBy('day');

        // First and last reading of each day. A day is only measurable when the
        // previous day also has one - otherwise the drop spans an unknown gap.
        $readings = DB::select("
            select {$this->klDay('recorded_at')} as day,
                   min(recorded_at) as first_at,
                   max(recorded_at) as last_at,
                   min(balance)     as closing,
                   count(*)         as n
            from ai_balance_log
            group by day
            order by day
        ");

        $out = [];
        $previous = null;

        foreach ($readings as $r) {
            $est = $estimated[$r->day] ?? null;

            $actual = null;

            if ($previous !== null) {
                $drop = $previous - (float) $r->closing;

                // A rise is a top-up, not negative spending: unmeasurable.
                $actual = $drop >= 0 ? round($drop, 4) : null;
            }

            $out[] = [
                'day'       => $r->day,
                'calls'     => (int) ($est->calls ?? 0),
                'estimated' => round((float) ($est->estimated ?? 0), 4),
                'actual'    => $actual,
                'readings'  => (int) $r->n,
                'last_at'   => $r->last_at,
                // A day whose last reading is not near its end has only counted
                // part of it, and the gap is that, not a bad estimate.
                'partial'   => $previous !== null
                    && \Carbon\Carbon::parse($r->last_at)->setTimezone('Asia/Kuala_Lumpur')->hour < 23,
            ];

            $previous = (float) $r->closing;
        }

        return array_reverse($out);
    }

    /** The Malaysian calendar day of a stored UTC timestamp. */
    private function klDay(string $column): string
    {
        return "({$column} AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Kuala_Lumpur')::date";
    }

    /**
     * The SQL for one column of the Live screen, written once.
     *
     * The counts and the drill-down MUST agree. Written twice they drift, and a
     * screen where clicking 531 returns 528 rows is worse than no screen -
     * every other number on it becomes suspect too.
     */
    private function bucketPredicate(string $bucket): string
    {
        return match ($bucket) {
            'live'      => 'pins > 0',
            'one_place' => 'pins > 0 and placed = 1',
            'many'      => 'pins > 0 and placed > 1',
            'national'  => 'pins > 0 and placed = 0 and national > 0',
            'no_place'  => 'pins > 0 and placed = 0 and national = 0',
            'duplicate' => "pins = 0 and ai_status = 'duplicate'",
            'discarded' => "pins = 0 and ai_status is distinct from 'duplicate'
                              and (discarded or ai_status = 'discarded')",
            'waiting'   => "pins = 0 and ai_status = 'pending'",
            'held'      => "pins = 0
                              and ai_status is distinct from 'duplicate'
                              and not (discarded or ai_status = 'discarded')
                              and ai_status is distinct from 'pending'",
            default     => 'true',   // ingested
        };
    }

    private const BUCKET_LABELS = [
        'ingested'  => 'everything that arrived',
        'live'      => 'live on the site',
        'one_place' => 'live, at one location',
        'many'      => 'live, at more than one location',
        'national'  => 'live, deliberately nowhere',
        'no_place'  => 'live, but never placed on the map',
        'duplicate' => 'already held from another source',
        'discarded' => 'refused by the playbook',
        'waiting'   => 'still queued for classification',
        'held'      => 'not published, and no reason on file',
    ];

    /**
     * One day, opened up.
     *
     * Two shapes, because two questions are being asked. Clicking a count asks
     * "which stories are these" and gets a list. Clicking the day's total asks
     * "where did they come from" and gets a source-by-source table - which is
     * the question that leads somewhere, because a source giving four fifths
     * duplicates or four fifths discards is a source to stop reading.
     */
    public function liveDay(Request $request, string $date): View
    {
        // Rejected rather than coerced: a malformed date silently becoming
        // today would show a plausible page for the wrong day.
        //
        // The route regex only proves the SHAPE is 0000-00-00. Carbon happily
        // rolls 2026-13-45 forward into a real date, and the raw string then
        // reached Postgres and produced a 500. Parse it, then require that it
        // round-trips to exactly what was asked for, and query with the parsed
        // value rather than the text.
        try {
            $day = \Carbon\Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable $e) {
            abort(404);
        }

        if ($day->format('Y-m-d') !== $date) {
            abort(404);
        }

        $date = $day->format('Y-m-d');

        $bucket = (string) $request->query('bucket', 'ingested');

        if ($bucket !== 'ingested' && !isset(self::BUCKET_LABELS[$bucket])) {
            abort(404);
        }

        $country    = $this->liveCountry($request);
        $countrySql = $country === 'ALL' ? '' : "and n.geo_claim_country = '{$country}'";

        // The same per-story shape the counts are built from, for one day.
        $base = "
            with feed as (
                select news_item_id,
                       count(*)                                                  as pins,
                       count(*) filter (where lat is not null and lng is not null) as placed,
                       count(*) filter (where relevance_mode = 'category_only')  as national
                from feed_ready_items
                where is_active
                group by news_item_id
            ),
            spend as (
                select news_item_id, sum(estimated_cost) as cost
                from ai_processing_jobs
                group by news_item_id
            ),
            per_story as (
                select
                    n.id, n.title, n.url, n.source, n.published_at, n.created_at, n.published_precision,
                    n.ai_status, n.discarded, n.ai_category, n.sub_category,
                    n.main_place_text, n.canonical_place_name,
                    n.latitude, n.longitude, n.relevance_mode,
                    coalesce(f.pins, 0)     as pins,
                    coalesce(f.placed, 0)   as placed,
                    coalesce(f.national, 0) as national,
                    coalesce(s.cost, 0)     as cost
                from news_items n
                left join feed  f on f.news_item_id = n.id
                left join spend s on s.news_item_id = n.id
                where {$this->klDay('n.published_at')} = ?
                  {$countrySql}
            )
        ";

        $where = $this->bucketPredicate($bucket);

        // ── The day's total opens the source breakdown ────────────────────
        if ($bucket === 'ingested') {
            $sources = DB::select($base . "
                select
                    coalesce(nullif(trim(source), ''), '(no source)')            as source,
                    count(*)                                                     as ingested,
                    count(*) filter (where {$this->bucketPredicate('live')})      as live,
                    count(*) filter (where {$this->bucketPredicate('one_place')}) as one_place,
                    count(*) filter (where {$this->bucketPredicate('many')})      as many_places,
                    count(*) filter (where {$this->bucketPredicate('national')})  as national,
                    count(*) filter (where {$this->bucketPredicate('no_place')})  as no_place,
                    count(*) filter (where {$this->bucketPredicate('duplicate')}) as duplicate,
                    count(*) filter (where {$this->bucketPredicate('discarded')}) as discarded,
                    count(*) filter (where {$this->bucketPredicate('waiting')})   as waiting,
                    count(*) filter (where {$this->bucketPredicate('held')})      as held,
                    round(sum(cost)::numeric, 4)                                 as cost
                from per_story
                group by source
                order by ingested desc, source
            ", [$date]);

            return view('admin.brain.live-day', [
            'country' => $country,
                'date'    => $day,
                'bucket'  => $bucket,
                'label'   => self::BUCKET_LABELS['ingested'],
                'sources' => $sources,
                'stories' => null,
            ]);
        }

        // ── Any other count opens the stories behind it ───────────────────
        //
        // Paginated, not capped. A flat limit of 500 meant clicking a day's
        // 531 live stories returned 500 of them - which is precisely the
        // "click 531, get 528" failure this page is built to avoid, arriving
        // by a different door. The count is the truth; the page shows all of
        // it, a screenful at a time.
        $perPage = 100;
        $page    = max(1, (int) $request->query('page', 1));

        $found = (int) DB::selectOne($base . "
            select count(*) as n from per_story where {$where}
        ", [$date])->n;

        $stories = DB::select($base . "
            select * from per_story
            where {$where}
            order by published_at desc, id desc
            limit {$perPage} offset ?
        ", [$date, ($page - 1) * $perPage]);

        // The places behind the count.
        //
        // "3 pins" beside a single place name is a screen calling itself a liar.
        // The count comes from feed_ready_items, which holds one row per place a
        // story was served at; the label was being taken from the story's own
        // canonical_place_name, which is one field and can only ever name one
        // place. So a story genuinely served at Taman Negara Terengganu, Tasik
        // Kenyir and the Gerik east-west highway showed "3 pins - Taman Negara
        // Terengganu", and the obvious reading was that the count was wrong.
        //
        // One query for the whole page rather than one per row - cheap now that
        // feed_ready_items has an index on news_item_id.
        $pins = [];

        if ($stories !== []) {
            $rows = DB::table('feed_ready_items')
                ->whereIn('news_item_id', array_column($stories, 'id'))
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['news_item_id', 'location_label', 'lat', 'lng', 'relevance_mode']);

            foreach ($rows as $row) {
                $pins[$row->news_item_id][] = $row;
            }
        }

        foreach ($stories as $story) {
            $story->pin_list = $pins[$story->id] ?? [];
        }

        $stories = new \Illuminate\Pagination\LengthAwarePaginator(
            $stories, $found, $perPage, $page,
            [
                'path'  => route('admin.brain.live.day', ['date' => $date]),
                'query' => ['bucket' => $bucket],
            ]
        );

        return view('admin.brain.live-day', [
            'country' => $country,
            'date'    => $day,
            'bucket'  => $bucket,
            'label'   => self::BUCKET_LABELS[$bucket],
            'sources' => null,
            'stories' => $stories,
        ]);
    }

    public function corrections(): View
    {
        return view('admin.brain.corrections', [
            'rows'   => DB::table('corrections')->orderByDesc('id')->limit(80)->get(),
            'fields' => CorrectionLog::FIELDS,
            'tally'  => $this->corrections->tally(),
        ]);
    }

    /**
     * Record a correction, and put the story on the bench in the same motion.
     *
     * Separating the two would mean a correction teaches nothing until someone
     * remembers to come back and add it - which nobody does, which is how the
     * removals table ended up with no rows in it at all.
     */
    public function correct(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'news_item_id'   => ['required', 'integer'],
            'field'          => ['required', 'string', 'max:20'],
            'correct_answer' => ['nullable', 'string', 'max:2000'],
            'reason'         => ['nullable', 'string', 'max:2000'],
        ]);

        $story = DB::table('news_items')->where('id', $data['news_item_id'])->first();

        if (!$story) {
            return back()->withErrors(['news_item_id' => 'No such story.']);
        }

        $was = match ($data['field']) {
            'keep', 'refused' => $story->discarded ? 'discarded' : 'published',
            'place', 'nowhere' => $story->main_place_text ?: '(nowhere)',
            'category' => $story->ai_category,
            'sub' => $story->sub_category,
            default => null,
        };

        $this->corrections->record(
            (int) $data['news_item_id'],
            $data['field'],
            $was,
            $data['correct_answer'] ?? null,
            $data['reason'] ?? null,
        );

        $id = DB::table('corrections')->max('id');
        $this->corrections->promoteToBench((int) $id);

        return back()->with('status', 'Recorded, and the story is on the bench so the same mistake gets measured from now on.');
    }
}

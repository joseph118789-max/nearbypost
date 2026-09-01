<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\Adapters;
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
            'readFull'     => $this->reading('full'),
            'readTeaser'   => $this->reading('teaser'),
            'readHeld'     => $this->reading('held'),
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

    // ── The playbook ──────────────────────────────────────────────────────

    public function playbook(): View
    {
        return view('admin.brain.playbook', [
            'sections'  => $this->playbook->sections(),
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

        $this->playbook->save($key, $data['body'], $data['note'] ?? null, Auth::guard('admin')->id());

        return back()->with('status', 'Saved. The next story judged will use it.');
    }

    public function togglePlaybook(string $key): RedirectResponse
    {
        $section = $this->playbook->sections()[$key] ?? null;

        if (!$section) {
            return back();
        }

        $this->playbook->setActive($key, !$section['is_active']);

        return back()->with('status', $section['is_active']
            ? 'Switched off. The model will no longer be told this.'
            : 'Switched back on.');
    }

    // ── The Malaysia briefing ─────────────────────────────────────────────

    public function briefing(): View
    {
        return view('admin.brain.briefing', [
            'terms' => DB::table('briefing_terms')->orderBy('kind')->orderBy('term')->get(),
            'kinds' => Briefing::KINDS,
            'sent'  => count($this->briefing->active()),
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

        DB::table('briefing_terms')->updateOrInsert(
            ['term' => $data['term'], 'kind' => $data['kind']],
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

    public function bench(): View
    {
        $runs = DB::table('bench_runs')->orderByDesc('id')->limit(10)->get();

        return view('admin.brain.bench', [
            'items' => DB::table('bench_items')->where('proposed', false)->orderByDesc('id')->limit(60)->get(),
            'count' => DB::table('bench_items')->where('proposed', false)->count(),
            'proposals' => DB::table('bench_items')->where('proposed', true)->orderBy('expect_keep')->orderBy('id')->get(),
            'runs'  => $runs->map(function ($run) {
                $run->parsed = $run->scores ? json_decode($run->scores, true) : null;

                return $run;
            }),
            // Recent stories with what the AI decided, so confirming an answer
            // is one click. Most answers are right; the bench fills fastest by
            // agreeing quickly and stopping to correct only what is wrong.
            'recent' => DB::table('news_items as n')
                ->leftJoin('bench_items as b', 'b.news_item_id', '=', 'n.id')
                ->whereNotNull('n.ai_processed_at')
                ->whereNull('b.id')
                ->orderByDesc('n.ai_processed_at')
                ->limit(25)
                ->get([
                    'n.id', 'n.title', 'n.discarded', 'n.ai_category',
                    'n.sub_category', 'n.main_place_text',
                ]),
            'fields' => CorrectionLog::FIELDS,
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
    public function acceptProposal(Request $request, ?int $id = null): RedirectResponse
    {
        $query = DB::table('bench_items')->where('proposed', true);

        if ($id !== null) {
            $query->where('id', $id);
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

    public function rejectProposal(int $id): RedirectResponse
    {
        DB::table('bench_items')->where('id', $id)->where('proposed', true)->delete();

        return back()->with('status', 'Thrown away. Nothing was added to the bench.');
    }

    public function removeBenchItem(int $id): RedirectResponse
    {
        DB::table('bench_items')->where('id', $id)->delete();

        return back()->with('status', 'Removed from the bench.');
    }

    // ── Corrections ───────────────────────────────────────────────────────

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

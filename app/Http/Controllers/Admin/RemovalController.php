<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contribution\ReviewRules;
use App\Services\Contribution\RuleSuggester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * What was taken down, and what that should teach the reviewer.
 *
 * The loop this closes: an editor removes a story and says why; enough of those
 * reasons accumulate to show a pattern; the pattern becomes a house rule; and
 * the reviewer stops making that mistake. Without the last two steps an editor
 * is simply doing the same correction for ever.
 *
 * Nothing here writes a rule by itself. The model reads the reasons and
 * proposes wording; a person decides whether it becomes policy. A rule the
 * newsroom did not agree to is not policy, it is drift.
 */
class RemovalController extends Controller
{
    /** Below this there is not enough to see a pattern in. */
    private const ENOUGH_TO_LEARN_FROM = 5;

    public function __construct(
        private RuleSuggester $suggester,
        private ReviewRules $rules,
    ) {
    }

    public function index(): View
    {
        $removals = DB::table('removals')->orderByDesc('created_at')->limit(200)->get();

        return view('admin.removals', [
            'removals'   => $removals,
            'unreviewed' => DB::table('removals')->whereNull('reviewed_at')->count(),
            'enough'     => self::ENOUGH_TO_LEARN_FROM,
            'proposals'  => session('proposals', []),
        ]);
    }

    /**
     * Read the reasons and propose rules; write none of them.
     */
    public function suggest(): RedirectResponse
    {
        $reasons = DB::table('removals')
            ->whereNull('reviewed_at')
            ->orderBy('created_at')
            ->limit(120)
            ->get(['title', 'origin', 'reason']);

        if ($reasons->count() < self::ENOUGH_TO_LEARN_FROM) {
            return back()->with('status',
                'Only ' . $reasons->count() . ' removal(s) to learn from. Wait until there are at least '
                . self::ENOUGH_TO_LEARN_FROM . ' - fewer than that is anecdote, not a pattern.');
        }

        $proposals = $this->suggester->propose($reasons->all(), $this->rules->active('both'));

        if ($proposals === []) {
            return back()->with('status', 'Nothing new to propose: these removals are already covered by the rules you have.');
        }

        return back()->with('proposals', $proposals)->with('status',
            count($proposals) . ' rule(s) proposed from ' . $reasons->count() . ' removal(s). Nothing is in force until you add it.');
    }

    /** Accept one proposed rule into policy. */
    public function accept(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule'       => ['required', 'string', 'min:6', 'max:400'],
            'applies_to' => ['required', 'in:both,contributor,scraper'],
        ]);

        DB::table('review_rules')->insert([
            'rule'       => trim($data['rule']),
            'applies_to' => $data['applies_to'],
            'is_active'  => true,
            'sort_order' => (int) DB::table('review_rules')->max('sort_order') + 1,
            'created_by' => Auth::guard('admin')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rules->bumpVersion();

        // These removals have now taught what they had to teach.
        DB::table('removals')->whereNull('reviewed_at')->update(['reviewed_at' => now()]);

        return redirect()->route('admin.rules.index')
            ->with('status', 'Rule added from what was removed. It applies to the next submission.');
    }

    /** Set the removals aside without writing a rule. */
    public function dismiss(): RedirectResponse
    {
        DB::table('removals')->whereNull('reviewed_at')->update(['reviewed_at' => now()]);

        return back()->with('status', 'Marked as read. They stay in the list but will not be suggested again.');
    }
}

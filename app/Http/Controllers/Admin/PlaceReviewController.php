<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlaceReviewQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The places the machines could not identify.
 *
 * Everything else in this panel explains what the pipeline decided. This page
 * is the one place it admits it does not know, and asks. That is deliberate:
 * a launch given only as a PT lot number cannot be looked up by any service at
 * any price, but somebody in the office often knows exactly where it is.
 */
class PlaceReviewController extends Controller
{
    public function __construct(private PlaceReviewQueue $queue)
    {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');

        // Counted from the stories, not from a column. The column was bumped
        // on every geocoder pass and reached 9,050 for 119 real stories.
        $real = $this->queue->storyCounts();

        // Which state each waiting name is in, from its own text: "Pantai
        // Tanjung Lompat, Bandar Penawar, Kota Tinggi" is Johor because Kota
        // Tinggi is, and the district names are known as aliases of their
        // state. A name that gives no state at all sits in its own bucket.
        // The page shows the states first with a count each; a state opens
        // only its own names - sixty-seven cards in one scroll was nobody's
        // idea of a queue.
        $check = new \App\Services\Geo\Boundaries\BoundaryCheck(new \App\Services\Geo\Boundaries\BoundaryStore());
        $wantState = $request->query('state');   // a state code, '-' for unknown, null for the summary

        $all = DB::table('place_reviews')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit(400)
            ->get()
            ->map(function ($r) use ($real, $check) {
                $r->attempts    = json_decode($r->attempts ?? '[]', true) ?: [];
                $r->suggestions = json_decode($r->suggestions ?? '[]', true) ?: [];
                $r->placed_at   = json_decode($r->placed_at ?? 'null', true) ?: null;
                $r->story_count = $real[$r->id] ?? ($r->status === 'pending' ? 0 : $r->story_count);

                $home = \App\Services\Geo\SourceCountry::iso2($r->source);
                $at   = $check->stateOfKnownPlace($r->place_text, $home);
                $r->state_code = $at['state'] ?? '-';
                $r->state_name = $at['state_name'] ?? 'State unknown';
                $r->country    = $at['country'] ?? null;

                return $r;
            })
            ->sortByDesc(fn ($r) => [$r->story_count, $r->created_at])
            ->values();

        // The summary: one line per state, most names first, unknown last.
        $byState = [];

        foreach ($all as $r) {
            $k = $r->state_code;
            $byState[$k] ??= ['code' => $k, 'name' => $r->state_name, 'names' => 0, 'stories' => 0];
            $byState[$k]['names']++;
            $byState[$k]['stories'] += (int) $r->story_count;
        }

        uasort($byState, fn ($a, $b) => $a['code'] === '-' ? 1 : ($b['code'] === '-' ? -1 : [$b['names'], $a['name']] <=> [$a['names'], $b['name']]));

        $reviews = $wantState === null ? collect() : $all->filter(fn ($r) => $r->state_code === $wantState)->values();
        $stateName = $wantState === null ? null : ($byState[$wantState]['name'] ?? ($wantState === '-' ? 'State unknown' : $wantState));

        $counts = DB::table('place_reviews')
            ->selectRaw('status, count(*) as n, sum(story_count) as stories')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        if (isset($counts['pending'])) {
            $counts['pending']->stories = array_sum($real);
        }

        return view('admin.places.index', [
            'reviews'   => $reviews,
            'status'    => $status,
            'counts'    => $counts,
            'byState'   => array_values($byState),
            'state'     => $wantState,
            'stateName' => $stateName,
        ]);
    }

    public function resolve(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            // Malaysia, plus room for a story that genuinely happened abroad.
            'lat'   => ['required', 'numeric', 'between:-90,90'],
            'lng'   => ['required', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:300'],
            'note'  => ['nullable', 'string', 'max:2000'],
        ]);

        $released = $this->queue->resolve(
            $id,
            (float) $data['lat'],
            (float) $data['lng'],
            $data['label'] ?? null,
            $data['note'] ?? null,
            Auth::guard('admin')->user()->name ?? 'admin'
        );

        return back()->with('status', $released === 1
            ? 'Placed, and 1 story released.'
            : "Placed, and {$released} stories released.");
    }

    public function dismiss(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->queue->dismiss(
            $id,
            $data['note'] ?? null,
            Auth::guard('admin')->user()->name ?? 'admin'
        );

        return back()->with('status', 'Closed without placing it.');
    }
}

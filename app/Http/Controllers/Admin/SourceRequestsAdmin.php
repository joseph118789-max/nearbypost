<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ingest\SourceRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The desk for sites that asked to be listed.
 *
 * Each row arrives with three things beside it: what the publisher said, what
 * the probe actually found, and what a model made of both. They are shown
 * separately and labelled, because a claim, a measurement and an opinion are
 * three different kinds of thing and a moderator deciding between them should
 * be able to tell which is which.
 *
 * ⛔ Approving creates a source that is switched OFF. Saying "yes, this is
 * real and here is how to read it" is not the same as saying "put it in the
 * feed tonight".
 */
class SourceRequestsAdmin extends Controller
{
    public function index(Request $request): View
    {
        $show = (string) $request->query('status', 'open');

        $q = DB::table('source_requests')->orderByDesc('id');

        if ($show === 'open') {
            $q->whereIn('status', ['new', 'probing', 'reviewed']);
        } elseif ($show !== 'all') {
            $q->where('status', $show);
        }

        $rows = $q->limit(120)->get()->map(function ($r) {
            $r->probe_data   = json_decode((string) $r->probe, true) ?: [];
            $r->social       = json_decode((string) $r->social_links, true) ?: [];
            $r->source_name  = $r->created_source_id
                ? DB::table('sources')->where('id', $r->created_source_id)->value('name')
                : null;

            return $r;
        });

        return view('admin.source-requests', [
            'rows'   => $rows,
            'show'   => $show,
            'counts' => DB::table('source_requests')
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $cadence = $request->input('cadence') === 'daily' ? 'daily' : 'weekly';
        $result  = SourceRequests::approve($id, (int) (Auth::guard('admin')->id() ?? 0), $cadence);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok']
                ? 'Added as a source, switched off. Turn it on from Sources when you are ready.'
                : ($result['why'] ?? 'That did not work.')
        );
    }

    public function decline(Request $request, int $id): RedirectResponse
    {
        $result = SourceRequests::decline(
            $id,
            (int) (Auth::guard('admin')->id() ?? 0),
            (string) $request->input('reason', '')
        );

        return back()->with($result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Declined, with the reason on the record.' : ($result['why'] ?? 'Say why first.'));
    }

    /**
     * Look for the verification token now.
     *
     * ⛔ Verifying is not approving. It says the submitter controls the domain,
     * which is the only claim about a website that can be checked from outside.
     * Whether the site belongs in the feed is still this desk's decision.
     */
    public function verify(int $id): RedirectResponse
    {
        $r = \App\Services\Ingest\SourceOwnership::check($id);

        return back()->with($r['verified'] ? 'status' : 'error', $r['why']);
    }

    /** A publisher withdrawing: stop fetching AND take down what is served. */
    public function revoke(Request $request, int $id): RedirectResponse
    {
        $name = DB::table('sources')->where('id', $id)->value('name');

        if ($name === null) {
            return back()->with('error', 'No such source.');
        }

        $r = \App\Services\Ingest\SourcePermissions::revoke(
            $name,
            (string) $request->input('reason', ''),
            (int) (Auth::guard('admin')->id() ?? 0)
        );

        return back()->with($r['ok'] ? 'status' : 'error',
            $r['ok'] ? 'Withdrawn. ' . ($r['stopped'] ?? 0) . ' item(s) taken off the site.' : $r['why']);
    }

    /** Re-probe on demand, when a site has changed since it was looked at. */
    public function recheck(int $id): RedirectResponse
    {
        DB::table('source_requests')->where('id', $id)
            ->whereIn('status', ['reviewed', 'new', 'probing'])
            ->update(['status' => 'new', 'updated_at' => now()]);

        SourceRequests::assess($id);

        return back()->with('status', 'Looked again.');
    }
}

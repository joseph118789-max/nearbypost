<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Community\CommunityTrust;
use App\Services\FeedQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** The moderation desk: held, disputed, reported and recently published community reports, with reasons on every action. */
class CommunityAdminController extends Controller
{
    public function index(Request $request): View
    {
        $view = $request->query('view', 'attention');
        $q = DB::table('community_post_meta as m')->join('news_items as n', 'n.id', '=', 'm.news_item_id')
            ->leftJoin('users as u', 'u.id', '=', 'n.contributor_id')
            ->select('m.*', 'n.title', 'n.status as story_status', 'n.review_status', 'n.review_reason', 'n.published_at', 'n.location_label', 'n.main_place_text', 'u.username', 'u.credibility')
            ->orderByDesc('m.updated_at');

        match ($view) {
            'held'     => $q->whereIn('m.moderation_status', ['held', 'pending_ai']),
            'disputed' => $q->where('m.trust_status', 'disputed'),
            'reported' => $q->where('m.report_count', '>', 0),
            'removed'  => $q->where('m.trust_status', 'removed'),
            'all'      => null,
            default    => $q->where(fn ($w) => $w->whereIn('m.moderation_status', ['held', 'pending_ai'])->orWhere('m.trust_status', 'disputed')->orWhere('m.report_count', '>', 0)),
        };

        return view('admin.community.index', ['rows' => $q->limit(200)->get(), 'view' => $view,
            'counts' => [
                'attention' => DB::table('community_post_meta')->where(fn ($w) => $w->whereIn('moderation_status', ['held', 'pending_ai'])->orWhere('trust_status', 'disputed')->orWhere('report_count', '>', 0))->count(),
                'held' => DB::table('community_post_meta')->whereIn('moderation_status', ['held', 'pending_ai'])->count(),
                'disputed' => DB::table('community_post_meta')->where('trust_status', 'disputed')->count(),
                'reported' => DB::table('community_post_meta')->where('report_count', '>', 0)->count(),
                'removed' => DB::table('community_post_meta')->where('trust_status', 'removed')->count(),
                'all' => DB::table('community_post_meta')->count(),
            ]]);
    }

    public function show(int $id): View
    {
        $meta = DB::table('community_post_meta')->where('news_item_id', $id)->first();
        abort_if(!$meta, 404);
        $item = DB::table('news_items')->where('id', $id)->first();
        $author = $item->contributor_id ? DB::table('users')->where('id', $item->contributor_id)->first() : null;

        return view('admin.community.show', [
            'meta' => $meta, 'item' => $item, 'author' => $author,
            'versions' => DB::table('community_post_versions')->where('news_item_id', $id)->orderBy('id')->get(),
            'checks' => DB::table('community_moderation_checks')->where('news_item_id', $id)->orderByDesc('id')->get(),
            'reports' => DB::table('community_reports')->where('news_item_id', $id)->orderByDesc('id')->get(),
            'reactions' => DB::table('community_reactions')->where('news_item_id', $id)->selectRaw('type, count(*) n, sum(weight) w')->groupBy('type')->get(),
            'history' => DB::table('community_status_history')->where('news_item_id', $id)->orderByDesc('id')->get(),
            'ledger' => $author ? DB::table('community_reputation_events')->where('user_id', $author->id)->orderByDesc('id')->limit(30)->get() : collect(),
            'distanceM' => ($meta->gps_lat_private !== null && $meta->selected_lat !== null) ? (int) $meta->pin_adjustment_m : null,
        ]);
    }

    public function appeals(): View
    {
        return view('admin.community.appeals', [
            'appeals' => DB::table('community_appeals as a')->join('news_items as n', 'n.id', '=', 'a.news_item_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->orderByRaw("case when a.status = 'open' then 0 else 1 end")->orderByDesc('a.id')->limit(100)->get(['a.*', 'n.title', 'u.username']),
            'corrections' => DB::table('community_corrections as c')->join('news_items as n', 'n.id', '=', 'c.news_item_id')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.status', 'open')->orderByDesc('c.id')->limit(100)->get(['c.*', 'n.title', 'u.username']),
            'comments' => DB::table('community_comment_reports as r')->join('community_comments as c', 'c.id', '=', 'r.comment_id')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->where('r.status', 'open')->orderByDesc('r.id')->limit(100)->get(['r.*', 'c.body', 'c.status as comment_status', 'c.news_item_id', 'u.username']),
            'moderators' => DB::table('users')->where('community_role', 'moderator')->get(['id', 'username', 'credibility', 'role_granted_at']),
            'candidates' => DB::table('users')->whereNull('community_role')->where('credibility', '>=', 80)->whereNotNull('trusted_at')->limit(20)->get(['id', 'username', 'credibility']),
            'modActions' => DB::table('community_moderator_actions as a')->join('users as u', 'u.id', '=', 'a.user_id')->orderByDesc('a.id')->limit(50)->get(['a.*', 'u.username']),
        ]);
    }

    public function resolveAppeal(Request $request, int $appealId, string $decision): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'min:3', 'max:400']]);
        $a = DB::table('community_appeals')->where('id', $appealId)->first();
        abort_if(!$a, 404);
        $admin = Auth::guard('admin')->id();

        if ($decision === 'uphold') {
            // restore: the story, its trust state, and the reputation effects of the removal
            \App\Services\Community\CommunityTrust::setStatus($a->news_item_id, 'unverified', 'moderator', $admin, 'appeal upheld: ' . $data['resolution']);
            DB::table('community_post_meta')->where('news_item_id', $a->news_item_id)->update(['moderation_status' => 'published', 'updated_at' => now()]);
            DB::table('community_reputation_events')->where('news_item_id', $a->news_item_id)->whereIn('event', ['removed', 'disputed'])->delete();
            DB::table('news_items')->where('id', $a->news_item_id)->update(['status' => 'active', 'review_status' => 'published', 'updated_at' => now()]);
            \App\Services\Community\CommunityTrust::ledger((int) $a->user_id, $a->news_item_id, 'appeal_upheld', 0, 2, 'appeal upheld');
            \App\Services\Community\CommunityTrust::refresh($a->news_item_id, 'appeal upheld');
        }

        DB::table('community_appeals')->where('id', $appealId)->update(['status' => $decision === 'uphold' ? 'upheld' : 'denied', 'resolved_by' => $admin, 'resolution' => $data['resolution'], 'resolved_at' => now(), 'updated_at' => now()]);
        \App\Services\Community\Notifications::send((int) $a->user_id, 'appeal', $decision === 'uphold' ? 'Your appeal was upheld' : 'Your appeal was not upheld', $data['resolution'], url('/contribute'), $a->news_item_id, null, 'appeal:' . $appealId);

        return back()->with('status', 'Appeal ' . ($decision === 'uphold' ? 'upheld' : 'denied') . '.');
    }

    public function resolveCorrection(Request $request, int $correctionId, string $decision): RedirectResponse
    {
        $note = (string) $request->input('note', '');
        $decision === 'accept'
            ? \App\Services\Community\Corrections::accept($correctionId, 'staff', Auth::guard('admin')->id(), $note)
            : \App\Services\Community\Corrections::reject($correctionId, 'staff', Auth::guard('admin')->id(), $note);

        return back()->with('status', 'Correction ' . $decision . 'ed.');
    }

    public function moderateComment(Request $request, int $commentId, string $decision): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        DB::table('community_comments')->where('id', $commentId)->update([
            'status' => $decision === 'restore' ? 'published' : ($decision === 'remove' ? 'removed' : 'hidden'), 'moderation_note' => $data['reason'], 'updated_at' => now(),
        ]);
        DB::table('community_comment_reports')->where('comment_id', $commentId)->where('status', 'open')->update(['status' => $decision === 'restore' ? 'rejected' : 'upheld', 'updated_at' => now()]);

        return back()->with('status', 'Comment ' . $decision . 'd.');
    }

    public function moderator(Request $request, int $userId, string $decision): RedirectResponse
    {
        DB::table('users')->where('id', $userId)->update($decision === 'assign'
            ? ['community_role' => 'moderator', 'role_granted_at' => now(), 'role_granted_by' => Auth::guard('admin')->id()]
            : ['community_role' => null, 'role_granted_at' => null, 'role_granted_by' => null]);

        return back()->with('status', 'Moderator ' . ($decision === 'assign' ? 'assigned' : 'removed') . '.');
    }

    public function reverseModeratorAction(int $actionId): RedirectResponse
    {
        DB::table('community_moderator_actions')->where('id', $actionId)->update(['reversed' => true, 'reversed_by' => Auth::guard('admin')->id()]);

        return back()->with('status', 'Marked reversed; undo the effect on the report page if needed.');
    }

    public function act(Request $request, int $id, string $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:400']]);
        $admin = Auth::guard('admin')->id();

        switch ($action) {
            case 'remove':  CommunityTrust::setStatus($id, 'removed', 'moderator', $admin, $data['reason']); break;
            case 'restore':
                CommunityTrust::setStatus($id, 'unverified', 'moderator', $admin, $data['reason']);
                DB::table('community_post_meta')->where('news_item_id', $id)->update(['moderation_status' => 'published', 'updated_at' => now()]);
                CommunityTrust::refresh($id, 'restored by a moderator');
                break;
            case 'hold':
                DB::table('community_post_meta')->where('news_item_id', $id)->update(['moderation_status' => 'held', 'updated_at' => now()]);
                DB::table('news_items')->where('id', $id)->update(['status' => 'held', 'review_status' => 'pending_review', 'review_reason' => mb_substr($data['reason'], 0, 300), 'updated_at' => now()]);
                CommunityTrust::history($id, 'moderation_status', 'published', 'held', 'moderator', $admin, $data['reason']);
                break;
            case 'publish':
                DB::table('community_post_meta')->where('news_item_id', $id)->update(['moderation_status' => 'published', 'updated_at' => now()]);
                DB::table('news_items')->where('id', $id)->update(['status' => 'active', 'review_status' => 'published', 'updated_at' => now()]);
                CommunityTrust::history($id, 'moderation_status', 'held', 'published', 'moderator', $admin, $data['reason']);
                break;
            case 'confirm': CommunityTrust::setStatus($id, 'confirmed', 'moderator', $admin, $data['reason']); break;
            case 'dispute': CommunityTrust::setStatus($id, 'disputed', 'moderator', $admin, $data['reason']); break;
            case 'rereview': \App\Jobs\ReReviewCommunityPost::dispatch($id, 'moderator', $data['reason']); break;
            case 'reject_reports':
                DB::table('community_reports')->where('news_item_id', $id)->where('status', 'open')->update(['status' => 'rejected', 'updated_at' => now()]);
                CommunityTrust::refresh($id, 'reports rejected by a moderator: ' . $data['reason']);
                break;
            default: abort(404);
        }

        return back()->with('status', 'Done: ' . $action . '.');
    }
}

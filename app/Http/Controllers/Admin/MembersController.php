<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Community\CommunityTrust;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Reader accounts: who they are, how far they are trusted, what they have posted (owner, 4 Sep 2026). */
class MembersController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $members = DB::table('users as u')
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('u.username', 'ILIKE', '%' . $q . '%')->orWhere('u.name', 'ILIKE', '%' . $q . '%')->orWhere('u.email', 'ILIKE', '%' . $q . '%');
            }))
            ->orderByDesc('u.id')
            ->paginate(50)->withQueryString();

        $ids = $members->pluck('id');
        $posts = DB::table('news_items')->whereIn('contributor_id', $ids)->where('origin', 'user')->selectRaw('contributor_id, count(*) as n, count(*) filter (where status = \'active\') as live')->groupBy('contributor_id')->get()->keyBy('contributor_id');
        $comments = DB::table('community_comments')->whereIn('user_id', $ids)->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        return view('admin.members', ['members' => $members, 'posts' => $posts, 'comments' => $comments, 'q' => $q,
            'total' => DB::table('users')->count(), 'trusted' => DB::table('users')->whereNotNull('trusted_at')->count()]);
    }

    /** Trust a member by hand: their next post goes live as soon as the automatic check passes. */
    public function trust(int $id): RedirectResponse
    {
        DB::table('users')->where('id', $id)->update(['trusted_at' => now(), 'trusted_by' => Auth::guard('admin')->id()]);

        return back()->with('status', 'Trusted.');
    }

    public static function level(int $credibility): string
    {
        return CommunityTrust::levelName($credibility);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
use App\Models\User;
use App\Services\Community\CommunityTrust;
use App\Services\FeedQuery;
use App\Services\Geo\NominatimRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Community Reports: what readers do with a report once it is up - confirm,
 * mark helpful, dispute, report - and who wrote it: the @username profile.
 * Reading needs no account; reacting and reporting may be anonymous (low
 * weight, rate-limited, by device token - never by IP as identity).
 */
class CommunityController extends Controller
{
    public const REACTIONS = ['saw', 'helpful', 'wrong'];
    public const REPORT_REASONS = ['false', 'misleading', 'wrong_location', 'wrong_time', 'old_image', 'duplicate', 'spam', 'harassment', 'personal_info', 'graphic', 'impersonation', 'other'];

    public function __construct(private FeedQuery $feed)
    {
    }

    /** The place under a pin, from our own map engine, for the create form. */
    public function reverse(Request $request): JsonResponse
    {
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');

        if ($lat === 0.0 && $lng === 0.0 || abs($lat) > 90 || abs($lng) > 180) {
            return response()->json(['label' => null]);
        }

        $cc = $request->query('cc', 'my');
        $route = NominatimRouter::baseFor($cc);

        try {
            $r = Http::withHeaders(['User-Agent' => 'Nearbypost/1.0 (contact@nearbypost.com)'])->timeout(6)
                ->get($route['url'] . '/reverse', ['lat' => $lat, 'lon' => $lng, 'format' => 'json', 'zoom' => 16, 'addressdetails' => 1]);
            $a = $r->json('address') ?: [];
            $parts = array_values(array_filter([
                $a['road'] ?? $a['pedestrian'] ?? null,
                $a['suburb'] ?? $a['neighbourhood'] ?? $a['village'] ?? $a['town'] ?? null,
                $a['city'] ?? $a['town'] ?? $a['county'] ?? null,
                $a['state'] ?? null,
            ]));
            $label = implode(', ', array_unique($parts)) ?: ($r->json('display_name') ? implode(', ', array_slice(explode(',', $r->json('display_name')), 0, 3)) : null);

            return response()->json(['label' => $label ? mb_substr($label, 0, 160) : null, 'address' => $a]);
        } catch (\Throwable $x) {
            return response()->json(['label' => null]);
        }
    }

    /** The reactions, report form and comments of one report, as a fragment for the feed popup. */
    public function panel(Request $request, int $id): View
    {
        $post = $this->community($id);
        $me = Auth::guard('web')->user();
        $mineQ = DB::table('community_reactions')->where('news_item_id', $post->id);
        $mine = ($me ? $mineQ->where('user_id', $me->id) : $mineQ->where('device_token', $this->device($request)))->value('type');
        $comments = DB::table('community_comments as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.news_item_id', $post->id)->whereIn('c.status', ['published', 'hidden'])->orderBy('c.id')
            ->get(['c.*', 'u.username', 'u.credibility', DB::raw("(select count(*) from news_items n where n.contributor_id = u.id and n.origin = 'user' and n.status = 'active') as posts")]);
        $blocked = $me ? DB::table('user_blocks')->where('blocker_id', $me->id)->pluck('kind', 'blocked_id') : collect();

        return view('partials.community-panel', [
            'post' => $post, 'mine' => $mine, 'counts' => self::counts($post->id), 'comments' => $comments, 'blocked' => $blocked,
            'flash' => session('status'),
        ]);
    }

    public function react(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $post = $this->community($id);
        $data = $request->validate(['type' => ['required', 'in:' . implode(',', self::REACTIONS)]]);
        $this->throttle($request, 'react', 30);
        $result = CommunityTrust::react($post->id, $data['type'], Auth::user(), $this->device($request), $this->ipHash($request));

        if ($request->expectsJson()) {
            return response()->json($result + $this->counts($post->id));
        }

        return back()->with('status', $result['result'] === 'added' ? __('site.reaction_saved') : __('site.reaction_removed'));
    }

    public function report(Request $request, int $id): RedirectResponse
    {
        $post = $this->community($id);
        $data = $request->validate([
            'reason'      => ['required', 'in:' . implode(',', self::REPORT_REASONS)],
            'explanation' => ['nullable', 'string', 'max:1000'],
            'evidence'    => ['nullable', 'url', 'max:500'],
        ]);
        $this->throttle($request, 'report', 5);
        CommunityTrust::report($post->id, $data['reason'], $data['explanation'] ?? null, $data['evidence'] ?? null, Auth::user(), $this->device($request), $this->ipHash($request));

        return back()->with('status', __('site.report_received'));
    }

    /** /@username */
    public function profile(string $username): View
    {
        $user = User::query()->whereRaw('lower(username) = ?', [strtolower($username)])->first();

        if (!$user) {
            throw new NotFoundHttpException('No such contributor');
        }

        $posts = NewsItem::query()->where('contributor_id', $user->id)->where('origin', 'user')
            ->where('status', 'active')->where('review_status', 'published')
            ->orderByDesc('published_at')->limit(50)->get();
        $metas = DB::table('community_post_meta')->whereIn('news_item_id', $posts->pluck('id'))->get()->keyBy('news_item_id');

        // ⛔ The Marketplace half of the SAME profile, not a second page.
        //
        // Spec 3.1: one person, one account, one public identity - community
        // posts, offers, businesses and reviews all belong to it. A separate
        // /u/{username} for the commercial side would split the person in two
        // and leave the reader deciding which half to trust.
        //
        // Nothing here merges: providers keep their own images and their own
        // ratings, and the reviews listed are ones this person WROTE, never
        // ones they received (spec 3.3).
        $marketplace = \App\Services\Marketplace\PersonProfile::forProfile((int) $user->id);

        return view('pages.community.profile', $marketplace + [
            'tab' => null, 'pageTitle' => '@' . $user->username, 'categories' => $this->feed->categories(),
            'profile' => $user, 'posts' => $posts, 'metas' => $metas,
            'level' => CommunityTrust::levelName((int) $user->credibility),
            'confirmed' => $metas->where('trust_status', 'confirmed')->count(),
            'followers' => DB::table('user_follows')->where('followed_id', $user->id)->count(),
            'following' => DB::table('user_follows')->where('follower_id', $user->id)->count(),
            'isFollowing' => Auth::check() && DB::table('user_follows')->where('follower_id', Auth::id())->where('followed_id', $user->id)->exists(),
            'canonical' => url('/@' . $user->username),
        ]);
    }

    public function follow(string $username): RedirectResponse
    {
        $user = User::query()->whereRaw('lower(username) = ?', [strtolower($username)])->firstOrFail();

        if ((int) $user->id !== (int) Auth::id()) {
            DB::table('user_follows')->insertOrIgnore(['follower_id' => Auth::id(), 'followed_id' => $user->id, 'created_at' => now()]);
        }

        return back();
    }

    public function unfollow(string $username): RedirectResponse
    {
        $user = User::query()->whereRaw('lower(username) = ?', [strtolower($username)])->firstOrFail();
        DB::table('user_follows')->where('follower_id', Auth::id())->where('followed_id', $user->id)->delete();

        return back();
    }

    /** Search contributors by @username or display name. */
    public function people(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $people = $q === '' ? collect() : User::query()->whereNotNull('username')
            ->where(fn ($w) => $w->whereRaw('lower(username) like ?', ['%' . strtolower($q) . '%'])->orWhereRaw('lower(name) like ?', ['%' . strtolower($q) . '%']))
            ->orderBy('username')->limit(30)->get();

        return view('pages.community.people', ['tab' => null, 'pageTitle' => __('site.people'), 'categories' => $this->feed->categories(), 'q' => $q, 'people' => $people]);
    }

    /** Counts shown on the page, weighted totals never exposed as raw voter data. */
    public static function counts(int $newsItemId): array
    {
        $rows = DB::table('community_reactions')->where('news_item_id', $newsItemId)->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type');

        return ['saw' => (int) ($rows['saw'] ?? 0), 'helpful' => (int) ($rows['helpful'] ?? 0), 'wrong' => (int) ($rows['wrong'] ?? 0)];
    }

    private function community(int $id): NewsItem
    {
        $post = NewsItem::query()->where('id', $id)->where('origin', 'user')->where('status', 'active')->first();

        if (!$post || !DB::table('community_post_meta')->where('news_item_id', $id)->exists()) {
            throw new NotFoundHttpException('No such report');
        }

        return $post;
    }

    /** A first-party device token in a cookie: the anonymous identity, never the IP. */
    private function device(Request $request): string
    {
        $token = (string) $request->cookie('np_device', '');

        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            cookie()->queue(cookie('np_device', $token, 60 * 24 * 365, null, null, true, true, false, 'Lax'));
        }

        return $token;
    }

    private function ipHash(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
    }

    private function throttle(Request $request, string $what, int $perHour): void
    {
        $key = $what . ':' . (Auth::id() ?: $this->device($request));

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            abort(429, __('site.too_many'));
        }

        RateLimiter::hit($key, 3600);
    }
}

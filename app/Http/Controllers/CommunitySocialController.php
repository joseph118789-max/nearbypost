<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
use App\Models\User;
use App\Services\Community\Badges;
use App\Services\Community\CommentModeration;
use App\Services\Community\CommunityTrust;
use App\Services\Community\Corrections;
use App\Services\Community\Moderators;
use App\Services\Community\Notifications;
use App\Services\FeedQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Community Reports, the conversation side: comments, corrections, appeals, follows, notifications, leaderboards, moderators. */
class CommunitySocialController extends Controller
{
    public function __construct(private FeedQuery $feed)
    {
    }

    // ---- comments -------------------------------------------------------------------------
    public function comment(Request $request, int $id): RedirectResponse
    {
        $post = $this->live($id);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000'], 'parent_id' => ['nullable', 'integer']]);
        $this->throttle('comment', 20);

        $parent = null;

        if (!empty($data['parent_id'])) {
            $parent = DB::table('community_comments')->where('id', $data['parent_id'])->where('news_item_id', $post->id)->first();
            abort_if(!$parent, 404);
            $data['parent_id'] = $parent->parent_id ?: $parent->id;   // one reply level
        }

        // the author's block list stands
        if ($post->contributor_id && DB::table('user_blocks')->where('blocker_id', $post->contributor_id)->where('blocked_id', Auth::id())->where('kind', 'block')->exists()) {
            return back()->with('status', __('site.cannot_comment_blocked'));
        }

        $commentId = DB::table('community_comments')->insertGetId([
            'news_item_id' => $post->id, 'user_id' => Auth::id(), 'parent_id' => $data['parent_id'] ?? null,
            'body' => trim(strip_tags($data['body'])), 'created_at' => now(), 'updated_at' => now(),
        ]);
        CommentModeration::check($commentId);

        $me = Auth::user();
        $target = $parent ? (int) $parent->user_id : (int) $post->contributor_id;

        if ($target && $target !== (int) Auth::id()) {
            Notifications::send($target, $parent ? 'reply' : 'comment', '@' . $me->username . ' ' . ($parent ? __('site.replied_to_you') : __('site.commented_on_your_report')),
                mb_substr($data['body'], 0, 200), url('/post/' . $post->id . '#c' . $commentId), $post->id, $commentId);
        }

        return redirect(url('/post/' . $post->id . '#c' . $commentId));
    }

    public function editComment(Request $request, int $commentId): RedirectResponse
    {
        $c = $this->ownComment($commentId);
        abort_if(abs(now()->diffInMinutes($c->created_at)) > 30, 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        DB::table('community_comments')->where('id', $commentId)->update(['body' => trim(strip_tags($data['body'])), 'edited_at' => now(), 'updated_at' => now()]);
        CommentModeration::check($commentId);

        return back();
    }

    public function deleteComment(int $commentId): RedirectResponse
    {
        $c = $this->ownComment($commentId);
        DB::table('community_comments')->where('id', $commentId)->update(['status' => 'removed', 'deleted_at' => now(), 'updated_at' => now()]);

        return back();
    }

    /**
     * A comment stays in the language it was written in; the reader asks for a
     * translation and gets it underneath (owner, 3 Sep). Made once per comment
     * and language by DeepSeek, then served from community_comment_translations.
     */
    public function translateComment(Request $request, int $comment): \Illuminate\Http\JsonResponse
    {
        $to = (string) $request->query('to', app()->getLocale());
        $names = ['en' => 'English', 'ms' => 'Malay (Bahasa Malaysia)', 'zh' => 'Simplified Chinese'];

        if (!isset($names[$to])) {
            return response()->json(['ok' => false, 'reason' => 'locale']);
        }

        $row = DB::table('community_comments')->where('id', $comment)->where('status', 'published')->first(['id', 'body']);

        if (!$row) {
            return response()->json(['ok' => false, 'reason' => 'missing']);
        }

        $cached = DB::table('community_comment_translations')->where('comment_id', $row->id)->where('locale', $to)->first();

        if ($cached) {
            return response()->json(['ok' => true, 'same' => $cached->source_lang === $to, 'lang' => $cached->source_lang, 'text' => $cached->body]);
        }

        try {
            $prompt = "Translate this reader comment from a local-news site into {$names[$to]}. Keep place names, names of people and numbers exactly as written. "
                . "Reply with JSON only: {\"lang\": \"<ISO 639-1 code of the comment's own language, e.g. en, ms, zh, ta>\", \"text\": \"<the translation>\"}. "
                . "If the comment is already in {$names[$to]}, return it unchanged as text.\n\nComment:\n" . mb_substr($row->body, 0, 2000);
            $adapter = \App\Services\Ai\AiRouter::for('comment_translate');
            $raw = $adapter->complete($prompt, 0.1);
            $json = \App\Services\Ai\ModelJson::parse($raw);
            $text = trim((string) ($json['text'] ?? ''));
            $lang = strtolower(substr(trim((string) ($json['lang'] ?? '')), 0, 8)) ?: null;

            if ($text === '') {
                return response()->json(['ok' => false, 'reason' => 'empty']);
            }

            DB::table('community_comment_translations')->insert(['comment_id' => $row->id, 'locale' => $to, 'source_lang' => $lang, 'body' => $text, 'model' => $adapter->model(), 'created_at' => now()]);

            return response()->json(['ok' => true, 'same' => $lang === $to, 'lang' => $lang, 'text' => $text]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('comment translation failed: ' . $e->getMessage(), ['comment' => $row->id]);

            return response()->json(['ok' => false, 'reason' => 'failed']);
        }
    }

    public function reportComment(Request $request, int $commentId): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'in:spam,harassment,hate,personal_info,graphic,other'], 'explanation' => ['nullable', 'string', 'max:500']]);
        $this->throttle('comment-report', 10);
        DB::table('community_comment_reports')->insert(['comment_id' => $commentId, 'user_id' => Auth::id(), 'reason' => $data['reason'], 'explanation' => $data['explanation'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        if (DB::table('community_comment_reports')->where('comment_id', $commentId)->where('status', 'open')->count() >= 3) {
            DB::table('community_comments')->where('id', $commentId)->where('status', 'published')->update(['status' => 'hidden', 'moderation_note' => 'hidden after reader reports', 'updated_at' => now()]);
        }

        return back()->with('status', __('site.report_received'));
    }

    public function block(Request $request, string $username): RedirectResponse
    {
        $user = User::query()->whereRaw('lower(username) = ?', [strtolower($username)])->firstOrFail();
        $kind = $request->input('kind') === 'mute' ? 'mute' : 'block';

        if ((int) $user->id !== (int) Auth::id()) {
            if ($request->boolean('undo')) {
                DB::table('user_blocks')->where('blocker_id', Auth::id())->where('blocked_id', $user->id)->where('kind', $kind)->delete();
            } else {
                DB::table('user_blocks')->insertOrIgnore(['blocker_id' => Auth::id(), 'blocked_id' => $user->id, 'kind' => $kind, 'created_at' => now()]);
            }
        }

        return back();
    }

    // ---- corrections ----------------------------------------------------------------------
    public function proposeCorrection(Request $request, int $id): RedirectResponse
    {
        $post = $this->live($id);
        $data = $request->validate([
            'field' => ['required', 'in:' . implode(',', Corrections::FIELDS)], 'proposed_value' => ['required', 'string', 'min:2', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:1000'], 'evidence' => ['nullable', 'url', 'max:500'],
        ]);
        $this->throttle('correction', 10);
        $cid = DB::table('community_corrections')->insertGetId([
            'news_item_id' => $post->id, 'user_id' => Auth::id(), 'field' => $data['field'], 'proposed_value' => trim(strip_tags($data['proposed_value'])),
            'explanation' => $data['explanation'] ?? null, 'evidence_url' => $data['evidence'] ?? null,
            'proposer_weight' => CommunityTrust::weightFor(Auth::user(), false), 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($post->contributor_id && (int) $post->contributor_id !== (int) Auth::id()) {
            Notifications::send((int) $post->contributor_id, 'correction_proposed', __('site.correction_proposed_title'), mb_substr($data['proposed_value'], 0, 200), url('/post/' . $post->id . '#corrections'), $post->id, null, 'correction:' . $cid);
        }

        return back()->with('status', __('site.correction_sent'));
    }

    /** The author accepts or rejects; safety-critical fields (location, duplicate) go to staff instead. */
    public function resolveCorrection(Request $request, int $correctionId, string $decision): RedirectResponse
    {
        $c = DB::table('community_corrections')->where('id', $correctionId)->first();
        abort_if(!$c, 404);
        $post = NewsItem::query()->where('id', $c->news_item_id)->first();
        abort_if(!$post || (int) $post->contributor_id !== (int) Auth::id(), 403);

        if ($decision === 'accept' && in_array($c->field, ['location', 'duplicate'], true)) {
            return back()->with('status', __('site.correction_needs_staff'));
        }

        $decision === 'accept' ? Corrections::accept($correctionId, 'author', Auth::id(), $request->input('note')) : Corrections::reject($correctionId, 'author', Auth::id(), $request->input('note'));

        return back()->with('status', $decision === 'accept' ? __('site.correction_accepted') : __('site.correction_rejected'));
    }

    // ---- appeals --------------------------------------------------------------------------
    public function appeal(Request $request, int $id): RedirectResponse
    {
        $post = NewsItem::query()->where('id', $id)->where('contributor_id', Auth::id())->first();
        abort_if(!$post, 404);
        $data = $request->validate(['text' => ['required', 'string', 'min:10', 'max:2000']]);

        if (DB::table('community_appeals')->where('news_item_id', $post->id)->where('status', 'open')->exists()) {
            return back()->with('status', __('site.appeal_already_open'));
        }

        $meta = DB::table('community_post_meta')->where('news_item_id', $post->id)->first();
        DB::table('community_appeals')->insert([
            'news_item_id' => $post->id, 'user_id' => Auth::id(), 'against' => $meta->trust_status === 'removed' ? 'removed' : ($post->review_status === 'rejected' ? 'rejected' : ($meta->trust_status === 'disputed' ? 'disputed' : 'held')),
            'text' => $data['text'], 'deadline_at' => now()->addDays(14), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('status', __('site.appeal_sent'));
    }

    // ---- follows: areas and topics; notification preferences -----------------------------
    public function follows(): View
    {
        $uid = Auth::id();

        return view('pages.community.follows', [
            'tab' => null, 'pageTitle' => __('site.following'), 'categories' => $this->feed->categories(),
            'areas' => DB::table('area_follows')->where('user_id', $uid)->orderBy('id')->get(),
            'topics' => DB::table('topic_follows')->where('user_id', $uid)->orderBy('category')->get(),
            'people' => DB::table('user_follows')->join('users', 'users.id', '=', 'user_follows.followed_id')->where('follower_id', $uid)->get(['users.username', 'users.display_name']),
            'mode' => Auth::user()->notify_mode ?? 'daily',
            'allCategories' => $this->feed->categories(),
        ]);
    }

    public function followArea(Request $request): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:120'], 'lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'between:1,50'], 'category' => ['nullable', 'string', 'max:80']]);
        abort_if(DB::table('area_follows')->where('user_id', Auth::id())->count() >= 20, 429);
        DB::table('area_follows')->insert(['user_id' => Auth::id(), 'label' => $data['label'], 'lat' => $data['lat'], 'lng' => $data['lng'],
            'radius_km' => $data['radius_km'] ?? 5, 'category' => ($data['category'] ?? null) ?: null, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', __('site.area_followed'));
    }

    public function areaAction(Request $request, int $followId, string $action): RedirectResponse
    {
        $q = DB::table('area_follows')->where('id', $followId)->where('user_id', Auth::id());
        abort_if(!$q->exists(), 404);
        match ($action) { 'pause' => $q->update(['paused' => true]), 'resume' => $q->update(['paused' => false]), default => $q->delete() };

        return back();
    }

    public function followTopic(Request $request): RedirectResponse
    {
        $data = $request->validate(['category' => ['required', 'string', 'max:80']]);
        DB::table('topic_follows')->insertOrIgnore(['user_id' => Auth::id(), 'category' => $data['category'], 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', __('site.topic_followed'));
    }

    public function topicAction(int $followId, string $action): RedirectResponse
    {
        $q = DB::table('topic_follows')->where('id', $followId)->where('user_id', Auth::id());
        abort_if(!$q->exists(), 404);
        match ($action) { 'pause' => $q->update(['paused' => true]), 'resume' => $q->update(['paused' => false]), default => $q->delete() };

        return back();
    }

    public function notifyMode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:immediate,daily,none']]);
        DB::table('users')->where('id', Auth::id())->update(['notify_mode' => $data['mode']]);

        return back()->with('status', __('site.saved'));
    }

    public function notifications(): View
    {
        $rows = DB::table('community_notifications')->where('user_id', Auth::id())->orderByDesc('id')->limit(100)->get();
        DB::table('community_notifications')->where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);

        return view('pages.community.notifications', ['tab' => null, 'pageTitle' => __('site.notifications'), 'categories' => $this->feed->categories(), 'rows' => $rows]);
    }

    // ---- leaderboards ---------------------------------------------------------------------
    public function leaderboard(Request $request, ?string $area = null): View
    {
        $window = in_array($request->query('window'), ['weekly', 'monthly', 'all'], true) ? $request->query('window') : 'monthly';
        $town = $area ? urldecode($area) : null;

        return view('pages.community.leaderboard', ['tab' => null, 'pageTitle' => __('site.leaderboard'), 'categories' => $this->feed->categories(),
            'rows' => cache()->remember('leaderboard:' . md5((string) $town . $window), 300, fn () => Badges::leaderboard($town, $window)), 'window' => $window, 'town' => $town,
            'towns' => DB::table('news_items')->where('origin', 'user')->where('status', 'active')->whereNotNull('location_label')->distinct()->limit(200)->pluck('location_label')
                ->map(fn ($l) => Badges::town($l))->filter()->unique()->sort()->values()]);
    }

    // ---- trusted community moderators -----------------------------------------------------
    public function moderate(Request $request): View
    {
        abort_unless(Moderators::isModerator(Auth::user()), 403);
        $rows = DB::table('community_post_meta as m')->join('news_items as n', 'n.id', '=', 'm.news_item_id')
            ->where(fn ($w) => $w->where('m.report_count', '>', 0)->orWhere('m.trust_status', 'disputed'))
            ->orderByDesc('m.updated_at')->limit(50)->get(['n.id', 'n.title', 'n.location_label', 'm.trust_status', 'm.moderation_status', 'm.report_count', 'm.saw_weight', 'm.wrong_weight']);
        $actions = DB::table('community_moderator_actions')->where('user_id', Auth::id())->orderByDesc('id')->limit(30)->get();

        return view('pages.community.moderate', ['tab' => null, 'pageTitle' => __('site.moderation'), 'categories' => $this->feed->categories(), 'rows' => $rows, 'actions' => $actions,
            'actionList' => Moderators::ACTIONS, 'reasonCodes' => Moderators::REASON_CODES]);
    }

    public function moderateAct(Request $request, int $id): RedirectResponse
    {
        abort_unless(Moderators::isModerator(Auth::user()), 403);
        $data = $request->validate(['action' => ['required', 'in:' . implode(',', Moderators::ACTIONS)], 'reason_code' => ['required', 'in:' . implode(',', Moderators::REASON_CODES)],
            'reason' => ['required', 'string', 'min:5', 'max:400'], 'value' => ['nullable', 'string', 'max:2000'], 'comment_id' => ['nullable', 'integer']]);
        $msg = Moderators::act(Auth::user(), $data['action'], $id, $data['reason_code'], $data['reason'], $data['comment_id'] ?? null, $data['value'] ?? null);

        return back()->with('status', $msg);
    }

    // ---- helpers --------------------------------------------------------------------------
    private function live(int $id): NewsItem
    {
        $post = NewsItem::query()->where('id', $id)->where('origin', 'user')->where('status', 'active')->where('review_status', 'published')->first();

        if (!$post) {
            throw new NotFoundHttpException('No such report');
        }

        return $post;
    }

    private function ownComment(int $commentId): object
    {
        $c = DB::table('community_comments')->where('id', $commentId)->where('user_id', Auth::id())->first();
        abort_if(!$c, 404);

        return $c;
    }

    private function throttle(string $what, int $perHour): void
    {
        $key = $what . ':' . Auth::id();

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            abort(429, __('site.too_many'));
        }

        RateLimiter::hit($key, 3600);
    }
}

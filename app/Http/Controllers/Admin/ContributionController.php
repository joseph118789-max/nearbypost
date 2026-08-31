<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedReadyItem;
use App\Models\NewsItem;
use App\Models\User;
use App\Services\Contribution\ImageIntake;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One panel for everything on the site, sorted by who wrote it.
 *
 *   Waiting      reader submissions no editor has looked at yet
 *   Unofficial   everything readers have sent in, live or refused
 *   Official     the 11,000-odd stories the scraper gathered
 *
 * The same three words the reader sees in the Source filter, so the panel and
 * the site describe the site the same way.
 *
 * Everything is judged by the same two questions in the end - is it true, and
 * do we want it here - so a gathered article can be taken down exactly like a
 * contributed one, and put back. Taking down without a way back is a trap.
 */
class ContributionController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private ImageIntake $images)
    {
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['unofficial', 'official'], true)
            ? $request->query('tab')
            : 'waiting';

        $search = trim((string) $request->query('q', ''));

        // Which language to read the listed stories in. The site serves three;
        // an editor checking a translation should not have to open the public
        // page in three tabs to see them.
        $lang = in_array($request->query('lang'), ['ms', 'zh'], true)
            ? $request->query('lang')
            : 'en';

        $waiting = NewsItem::query()
            ->where('origin', 'user')
            ->where('review_status', 'pending_review')
            ->orderBy('created_at')
            ->get();

        $rows = $tab === 'waiting' ? null : $this->listing($tab, $search);

        $contributors = User::query()
            ->whereIn('id', $this->contributorIds($waiting, $rows))
            ->get()
            ->keyBy('id');

        return view('admin.contributions', [
            // Which of these a reader can actually reach. A news_item at
            // status 'active' may still have no serving row - enrichment
            // unfinished, promotion refused, its slot given to a duplicate -
            // so the status column cannot answer this.
            'served'       => $this->servedIds($rows),
            'tab'          => $tab,
            'search'       => $search,
            'lang'         => $lang,
            'translations' => $this->translationsFor($rows, $lang),
            'waiting'      => $waiting,
            'rows'         => $rows,
            'contributors' => $contributors,
            'trustedCount' => User::whereNotNull('trusted_at')->count(),
            'counts'       => $this->counts(),
        ]);
    }

    /**
     * One page of stories from one source.
     *
     * Official is `origin <> 'user'`, matching the reader's filter, so a story
     * from some future third origin is still counted as ours rather than
     * disappearing from both lists.
     */
    private function listing(string $tab, string $search): LengthAwarePaginator
    {
        $query = NewsItem::query();

        $tab === 'unofficial'
            ? $query->where('origin', 'user')
            : $query->where('origin', '<>', 'user');

        if ($search !== '') {
            // ILIKE: Postgres, and an editor looking for a story should not
            // have to match the headline's capitalisation.
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ILIKE', '%' . $search . '%')
                  ->orWhere('source', 'ILIKE', '%' . $search . '%')
                  ->orWhere('location_label', 'ILIKE', '%' . $search . '%');
            });
        }

        return $query
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Of the stories on this page, the ids a reader can actually reach.
     *
     * @return array<int, true>
     */
    private function servedIds($rows): array
    {
        if ($rows === null) {
            return [];
        }

        $ids = collect($rows->items())->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return FeedReadyItem::whereIn('news_item_id', $ids)
            ->where('is_active', true)
            ->pluck('news_item_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * The listed stories' headlines in one reading language.
     *
     * English is the stored original for most stories, so it needs no lookup;
     * the other two come from news_translations, and a story with no
     * translation yet simply keeps its original headline - the same fallback
     * the public feed makes.
     *
     * @return array<int, string>
     */
    private function translationsFor($rows, string $lang): array
    {
        if ($rows === null || $lang === 'en') {
            return [];
        }

        $ids = collect($rows->items())->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return DB::table('news_translations')
            ->whereIn('news_item_id', $ids)
            ->where('locale', $lang)
            ->pluck('title', 'news_item_id')
            ->all();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'waiting'    => NewsItem::where('origin', 'user')->where('review_status', 'pending_review')->count(),
            'unofficial' => NewsItem::where('origin', 'user')->count(),
            'official'   => NewsItem::where('origin', '<>', 'user')->count(),
        ];
    }

    /** @return list<int> */
    private function contributorIds($waiting, $rows): array
    {
        $ids = collect($waiting)->pluck('contributor_id');

        if ($rows !== null) {
            $ids = $ids->merge(collect($rows->items())->pluck('contributor_id'));
        }

        return $ids->filter()->unique()->values()->all();
    }

    /** Release one story, and optionally vouch for the person who wrote it. */
    public function approve(Request $request, int $id): RedirectResponse
    {
        $post = $this->queued($id);

        $post->update([
            'review_status' => 'published',
            'status'        => 'active',
            'approved_by'   => Auth::guard('admin')->id(),
            'approved_at'   => now(),
        ]);

        $message = 'Published.';

        if ($request->boolean('trust') && $post->contributor_id) {
            User::where('id', $post->contributor_id)->update([
                'trusted_at' => now(),
                'trusted_by' => Auth::guard('admin')->id(),
            ]);

            $message = 'Published, and this contributor is now trusted - their future posts go live once the AI check passes.';
        }

        return back()->with('status', $message);
    }

    /**
     * Refuse it, with a reason the contributor will read.
     *
     * Their words are kept rather than deleted: they can edit and resubmit, and
     * a refusal they cannot see the reason for teaches them nothing.
     */
    public function reject(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:280'],
        ]);

        $post = $this->queued($id);

        $post->update([
            'review_status' => 'rejected',
            'review_reason' => $data['reason'],
            'status'        => 'held',
            'approved_by'   => Auth::guard('admin')->id(),
            'approved_at'   => now(),
        ]);

        return back()->with('status', 'Turned down, and the contributor can see why.');
    }

    /**
     * Take any story out of the feed - gathered or contributed.
     *
     * `status` is what the observer watches, so changing it clears the serving
     * row as this saves and the story leaves the site immediately.
     */
    public function unpublish(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:280'],
        ]);

        $post = $this->anyStory($id);

        $changes = [
            'status'      => 'held',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
        ];

        // Only a contributed story has an author who will read the reason.
        if ($post->origin === 'user') {
            $changes['review_status'] = 'rejected';
            $changes['review_reason'] = $data['reason'] ?: 'Removed by an editor.';
        }

        $post->update($changes);

        return back()->with('status', 'Removed from the feed.');
    }

    /** Put a taken-down story back. */
    public function republish(int $id): RedirectResponse
    {
        $post = $this->anyStory($id);

        $changes = ['status' => 'active'];

        if ($post->origin === 'user') {
            $changes['review_status'] = 'published';
            $changes['review_reason'] = null;
        }

        $post->update($changes);

        // Putting a story back is a promotion, and promotion has gates. A story
        // whose enrichment never succeeded will not pass them, and some rows in
        // the feed predate the gates entirely - so ask whether it actually
        // returned rather than assuming it did.
        $restored = FeedReadyItem::where('news_item_id', $post->id)
            ->where('is_active', true)
            ->exists();

        if ($restored) {
            return back()->with('status', 'Back in the feed.');
        }

        $why = in_array($post->ai_status, ['success', 'fallback_used'], true)
            ? 'it has no usable location or category'
            : 'its enrichment never finished (' . ($post->ai_status ?: 'not processed') . ')';

        return back()->with('status', 'Marked active, but it has NOT returned to the feed: ' . $why . '. It needs to go through enrichment again before readers can see it.');
    }

    /** Stop trusting someone, so their next post comes back to the queue. */
    public function untrust(int $contributorId): RedirectResponse
    {
        User::where('id', $contributorId)->update([
            'trusted_at' => null,
            'trusted_by' => null,
        ]);

        return back()->with('status', 'That contributor is no longer trusted; their next post will wait here.');
    }

    /** Delete a story and its picture for good. */
    public function destroy(int $id): RedirectResponse
    {
        $post = $this->anyStory($id);

        $this->images->forget($post->image_path);
        $post->delete();

        return back()->with('status', 'Deleted.');
    }

    private function queued(int $id): NewsItem
    {
        $post = NewsItem::where('id', $id)
            ->where('origin', 'user')
            ->where('review_status', 'pending_review')
            ->first();

        if (!$post) {
            // Most often this means someone else in the newsroom has already
            // dealt with it, which is worth saying plainly rather than 500ing.
            throw new NotFoundHttpException('That submission is no longer waiting for review');
        }

        return $post;
    }

    private function anyStory(int $id): NewsItem
    {
        $post = NewsItem::find($id);

        if (!$post) {
            throw new NotFoundHttpException('No such story');
        }

        return $post;
    }
}

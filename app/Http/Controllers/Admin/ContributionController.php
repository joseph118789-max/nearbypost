<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\User;
use App\Services\Contribution\ImageIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The queue: reader submissions waiting for a person to look at them.
 *
 * The AI has already read everything here and said it is news. This is the
 * second question, which no model can answer - is it true, and do we want it
 * on our site with our name on it.
 *
 * Approving does two separate things, and the difference matters. "Publish"
 * releases one story. "Publish and trust" also says this person can be relied
 * on, and from then on their posts go live as soon as the AI passes them. The
 * queue exists to look at a new contributor's work, not to read the same
 * regular's copy for ever.
 */
class ContributionController extends Controller
{
    /** How many published reader posts to list alongside the queue. */
    private const RECENT_LIMIT = 40;

    public function __construct(private ImageIntake $images)
    {
    }

    public function index(Request $request): View
    {
        $waiting = NewsItem::query()
            ->where('origin', 'user')
            ->where('review_status', 'pending_review')
            ->orderBy('created_at')
            ->get();

        $recent = NewsItem::query()
            ->where('origin', 'user')
            ->whereIn('review_status', ['published', 'rejected', 'awaiting_marketplace'])
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get();

        $contributors = User::query()
            ->whereIn('id', $waiting->pluck('contributor_id')->merge($recent->pluck('contributor_id'))->unique()->filter())
            ->get()
            ->keyBy('id');

        return view('admin.contributions', [
            'waiting'      => $waiting,
            'recent'       => $recent,
            'contributors' => $contributors,
            'trustedCount' => User::whereNotNull('trusted_at')->count(),
        ]);
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

    /** Take a published reader post back out of the feed. */
    public function unpublish(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:280'],
        ]);

        $post = NewsItem::where('id', $id)->where('origin', 'user')->first();

        if (!$post) {
            throw new NotFoundHttpException('No such contribution');
        }

        // status is what the observer watches; changing it clears the serving
        // row, so the story leaves the feed as this saves.
        $post->update([
            'review_status' => 'rejected',
            'review_reason' => $data['reason'] ?: 'Removed by an editor.',
            'status'        => 'held',
            'approved_by'   => Auth::guard('admin')->id(),
            'approved_at'   => now(),
        ]);

        return back()->with('status', 'Removed from the feed.');
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

    /** Delete a refused post and its picture for good. */
    public function destroy(int $id): RedirectResponse
    {
        $post = NewsItem::where('id', $id)->where('origin', 'user')->first();

        if (!$post) {
            throw new NotFoundHttpException('No such contribution');
        }

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
}

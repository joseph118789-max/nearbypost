<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
use App\Services\FeedQuery;
use App\Support\Loc;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page a contributed story actually lives on.
 *
 * Gathered articles link out to the publisher who wrote them, which is the
 * whole arrangement: we summarise, they get the reader. A contributor's story
 * has nowhere else to go - they wrote it here - so it needs a page of its own,
 * and that page's address is what the card in the feed points at.
 *
 * Only published posts are reachable. A held or refused post is visible to its
 * author in their own list and to nobody else, so a refusal cannot be worked
 * around by sharing the link.
 */
class PostController extends Controller
{
    public function __construct(private FeedQuery $feed)
    {
    }

    public function show(int $id): View
    {
        $post = NewsItem::query()
            ->where('id', $id)
            ->where('origin', 'user')
            ->where('status', 'active')
            ->where('review_status', 'published')
            ->first();

        if (!$post) {
            throw new NotFoundHttpException('No such post');
        }

        // The reader's own language where we have it, the original otherwise -
        // the same fallback the feed makes, so a story does not change language
        // between the card and the page.
        $translation = DB::table('news_translations')
            ->where('news_item_id', $post->id)
            ->where('locale', Loc::current())
            ->first();

        $title = $translation->title ?? $post->title;

        return view('pages.post', [
            'tab'         => null,
            'post'        => $post,
            'headline'    => $title,
            'standfirst'  => $translation->summary ?? $post->summary,
            'pageTitle'   => $title,
            'description' => mb_substr((string) ($translation->summary ?? $post->summary), 0, 200),
            'canonical'   => url()->current(),
            'categories'  => $this->feed->categories(),
            'place'       => $post->location_label ?: $post->main_place_text,
            'coords'      => $post->latitude !== null
                ? ['lat' => (float) $post->latitude, 'lng' => (float) $post->longitude]
                : null,
        ]);
    }
}

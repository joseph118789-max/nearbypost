<?php

namespace App\Http\Controllers;

use App\Services\FeedQuery;
use App\Support\Loc;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A page of our own for a gathered story, so a forwarded link lands here.
 *
 * Every headline in the feed used to point straight at the publisher, which
 * meant a reader who forwarded what they were reading forwarded The Star's URL:
 * The Star's headline, The Star's picture, The Star's name on the card. This
 * site appeared nowhere in the exchange it had caused.
 *
 * So the headline now points here and JavaScript intercepts the click for the
 * popup, which keeps browsing fast without costing the story a real address.
 * Someone arriving from a forwarded link gets the page instead - what the story
 * is, where and when, and two ways onward: the publisher for the full article,
 * or the rest of the feed.
 *
 * ⛔ NOINDEX, deliberately. Nine thousand pages carrying a headline and two
 * sentences is thin content, and inviting a search engine to crawl all of them
 * would work against the place and topic pages that are actually meant to rank.
 * These exist to be shared, not to be found. The links out are still followed,
 * so the credit reaches the publisher.
 */
class StoryController extends Controller
{
    /** Enough to offer somewhere to go next without becoming a second feed. */
    private const RELATED = 6;

    public function __construct(private FeedQuery $feed)
    {
    }

    public function show(int $id): View
    {
        $story = $this->story($id);

        if ($story === null) {
            throw new NotFoundHttpException('No such story');
        }

        $place = $story->location_label ?: null;

        return view('pages.story', [
            'tab'         => null,
            'story'       => $story,
            'place'       => $place,
            'related'     => $this->related($story),
            'categories'  => $this->feed->categories(),
            'pageTitle'   => $story->title,
            'description' => mb_substr((string) $story->summary, 0, 200),
            'canonical'   => Loc::route('story', ['id' => $id]),
            // A reader's own photograph where there is one; otherwise the brand
            // card, which is what the layout falls back to.
            'ogImage'     => $story->image_path ? asset($story->image_path) : null,
            'robots'      => 'noindex, follow',
            'coords'      => $story->lat !== null
                ? ['lat' => (float) $story->lat, 'lng' => (float) $story->lng]
                : null,
        ]);
    }

    /**
     * The story, in the reader's language where we have translated it.
     *
     * A multi-point story has one feed row per place, so the primary row is
     * taken - the page is about the story, not about one of its locations.
     */
    private function story(int $id): ?object
    {
        return DB::table('feed_ready_items as f')
            ->leftJoin('news_translations as t', function ($join) {
                $join->on('t.news_item_id', '=', 'f.news_item_id')
                     ->where('t.locale', '=', Loc::current());
            })
            ->where('f.news_item_id', $id)
            ->where('f.is_active', true)
            ->orderByDesc('f.is_primary_location')
            ->orderBy('f.id')
            ->selectRaw('f.news_item_id, f.source, f.url, f.published_at, f.origin,
                         f.primary_category, f.sub_category, f.location_label,
                         f.lat, f.lng, f.image_path,
                         COALESCE(t.title, f.title) AS title,
                         COALESCE(t.summary, f.summary) AS summary')
            ->first();
    }

    /**
     * Somewhere to go next: the same place if the story has one, its subject if
     * not.
     *
     * Same place first because that is the promise of the site - someone who
     * opened a story about their own town is more likely to want the rest of
     * their town than the rest of the category.
     */
    private function related(object $story): array
    {
        $query = DB::table('feed_ready_items as f')
            ->leftJoin('news_translations as t', function ($join) {
                $join->on('t.news_item_id', '=', 'f.news_item_id')
                     ->where('t.locale', '=', Loc::current());
            })
            ->where('f.is_active', true)
            ->where('f.is_article', true)
            ->where('f.news_item_id', '!=', $story->news_item_id)
            ->where('f.published_at', '>=', now()->subDays(14));

        if ($story->location_label) {
            $query->where('f.location_label', $story->location_label);
        } elseif ($story->primary_category) {
            $query->where('f.primary_category', $story->primary_category);
        }

        // One row per story: a multi-point story has several, and listing the
        // same headline six times is not six things to read.
        return $query->selectRaw('DISTINCT ON (f.news_item_id) f.news_item_id,
                                  f.source, f.published_at, f.location_label,
                                  COALESCE(t.title, f.title) AS title')
            ->orderBy('f.news_item_id')
            ->orderByDesc('f.published_at')
            ->limit(self::RELATED)
            ->get()
            ->sortByDesc('published_at')
            ->values()
            ->all();
    }
}

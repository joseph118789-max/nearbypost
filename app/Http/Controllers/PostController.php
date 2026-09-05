<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
use App\Services\FeedQuery;
use App\Support\Loc;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
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

        $title = $translation->title ?? $post->ai_title ?? $post->title;

        // Community Reports: the report's trust state, its author, the reactions, and structured data for Search
        $community = DB::table('community_post_meta')->where('news_item_id', $post->id)->first();
        $author = $post->contributor_id ? DB::table('users')->where('id', $post->contributor_id)->first(['id', 'username', 'name', 'credibility']) : null;

        if ($author) {
            $author->posts = DB::table('news_items')->where('contributor_id', $author->id)->where('origin', 'user')->where('status', 'active')->count();
        }
        $jsonLd = null;

        if ($community && $community->trust_status !== 'removed') {
            $jsonLd = [
                '@context' => 'https://schema.org',
                '@type' => $community->seo_eligibility === 'news' ? 'NewsArticle' : 'Article',
                'headline' => mb_substr($title, 0, 110),
                'datePublished' => $post->published_at?->toIso8601String(),
                'dateModified' => ($community->last_materially_updated_at ? \Carbon\Carbon::parse($community->last_materially_updated_at) : $post->updated_at)?->toIso8601String(),
                'author' => $author && $author->username ? ['@type' => 'Person', 'name' => '@' . $author->username, 'url' => url('/@' . $author->username)] : ['@type' => 'Person', 'name' => $post->source],
                'publisher' => ['@type' => 'Organization', 'name' => 'Nearbypost', 'url' => url('/')],
                'mainEntityOfPage' => url()->current(),
                'image' => $post->image_path ? [asset($post->image_path)] : null,
                'contentLocation' => $post->latitude !== null ? ['@type' => 'Place', 'name' => $post->location_label ?: $post->main_place_text,
                    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float) $post->latitude, 'longitude' => (float) $post->longitude]] : null,
                'description' => 'Community Report · ' . ucfirst($community->trust_status),
            ];
            $jsonLd = array_filter($jsonLd, fn ($v) => $v !== null);
        }

        $comments = $community ? DB::table('community_comments as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.news_item_id', $post->id)->whereIn('c.status', ['published', 'hidden'])->orderBy('c.id')
            ->get(['c.*', 'u.username', 'u.credibility', DB::raw("(select count(*) from news_items n where n.contributor_id = u.id and n.origin = 'user' and n.status = 'active') as posts")]) : collect();
        $corrections = $community ? DB::table('community_corrections as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.news_item_id', $post->id)->orderByDesc('c.id')->limit(20)->get(['c.*', 'u.username']) : collect();
        $history = $community ? DB::table('community_status_history')->where('news_item_id', $post->id)->where('field', 'trust_status')->orderBy('id')->get() : collect();
        $blocked = Auth::guard('web')->check() ? DB::table('user_blocks')->where('blocker_id', Auth::guard('web')->id())->pluck('kind', 'blocked_id') : collect();

        return view('pages.post', [
            'community'   => $community,
            'comments'    => $comments,
            'corrections' => $corrections,
            'history'     => $history,
            'blocked'     => $blocked,
            'isTranslated'=> $translation !== null && ($post->source_language ?? 'en') !== Loc::current(),
            'sourceLocale'=> $post->source_language,
            'author'      => $author,
            'counts'      => $community ? \App\Http\Controllers\CommunityController::counts($post->id) : [],
            'jsonLd'      => $jsonLd,
            'tab'         => null,
            'post'        => $post,
            'headline'    => $title,
            'standfirst'  => $translation->summary ?? $post->summary,
            'pageTitle'   => $title,
            'description' => mb_substr((string) ($translation->summary ?? $post->summary), 0, 200),
            'canonical'   => url()->current(),
            // A reader's own photograph, where they attached one. Sharing their
            // post to WhatsApp should show what they saw, not the site's logo.
            'ogImage'     => $post->image_path ? asset($post->image_path) : null,
            'categories'  => $this->feed->categories(),
            'place'       => $post->location_label ?: $post->main_place_text,
            'coords'      => $post->latitude !== null
                ? ['lat' => (float) $post->latitude, 'lng' => (float) $post->longitude]
                : null,
        ]);
    }
}

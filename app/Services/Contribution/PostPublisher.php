<?php

namespace App\Services\Contribution;

use App\Models\NewsItem;
use App\Models\User;
use App\Services\LocationResolver;
use Illuminate\Support\Facades\DB;

/**
 * Put a reviewed contribution where the verdict says it belongs.
 *
 * A contributed post is an ordinary news_item, so once this has finished with
 * it the rest of the site - the feed queries, de-duplication, the three reading
 * languages, distance sorting - treats it exactly like a gathered article and
 * needs to know nothing about where it came from beyond the `origin` column the
 * reader's Source filter reads.
 *
 * Three outcomes, and the difference between the last two matters to the person
 * who wrote it:
 *
 *   published - accepted, filed, and live
 *   rejected  - the reviewer read it and said no, with a reason to act on
 *   held      - nobody could judge it, so it waits and can be resubmitted
 *
 * Collapsing "held" into "rejected" would tell a contributor their story was
 * refused when in fact the reviewer was unreachable, which is a lie about their
 * work and one they cannot do anything about.
 */
class PostPublisher
{
    public function __construct(
        private NewsworthinessReview $review,
        private LocationResolver $locations,
    ) {
    }

    /**
     * Review an unsaved or edited post and write the outcome.
     *
     * The item arrives carrying the contributor's own fields - title, body,
     * section, place, picture - and leaves saved, judged and, if it passed,
     * in the feed.
     */
    public function submit(NewsItem $item): NewsItem
    {
        $verdict = $this->review->review(
            (string) $item->section,
            (string) $item->title,
            (string) $item->body,
            $item->main_place_text
        );

        if (!$verdict['ok']) {
            return $this->withhold(
                $item,
                $verdict['why'] === __('site.review_unavailable') ? 'held' : 'rejected',
                $verdict['why']
            );
        }

        // Accepted but unfiled is not publishable: a story with no category
        // cannot be browsed to and would sit in the feed unreachable. That is a
        // failure of the review, not of the contributor, so it waits.
        if ($verdict['category'] === null) {
            return $this->withhold($item, 'held', __('site.review_unavailable'));
        }

        $place  = $verdict['place'] ?: $item->main_place_text;
        $coords = $place ? $this->locations->resolve($place) : null;

        $item->fill([
            'summary'          => $verdict['summary'] ?: mb_substr((string) $item->body, 0, 500),
            'ai_summary'       => $verdict['summary'],
            'primary_category' => $verdict['category'],
            'ai_category'      => $verdict['category'],
            'sub_category'     => $verdict['sub'],
            'main_place_text'  => $place,
            'review_status'    => 'published',
            'review_reason'    => $verdict['why'],
            'ai_status'        => 'success',
            'ai_model'         => 'deepseek-chat',
            'ai_processed_at'  => now(),
            'is_article'       => true,
        ]);

        if ($item->latitude !== null && $item->longitude !== null) {
            // Community Reports: the reader put a pin on the map. The pin is the
            // place - exact, theirs - and the place text only names it.
            $item->fill([
                'canonical_place_name' => $place ?: ($coords['label'] ?? null),
                'location_label'       => $place ?: ($coords['label'] ?? null),
                'precision_type'       => 'exact',
                'geocode_status'       => 'success',
                'geocode_provider'     => 'reader_pin',
                'geocoded_at'          => now(),
                'relevance_mode'       => 'location_and_category',
            ]);
        } elseif ($coords) {
            $item->fill([
                'latitude'             => $coords['lat'],
                'longitude'            => $coords['lng'],
                'canonical_place_name' => $coords['label'] ?? $place,
                'location_label'       => $coords['label'] ?? $place,
                'precision_type'       => 'approximate',
                'geocode_status'       => 'success',
                'geocoded_at'          => now(),
                'relevance_mode'       => 'location_and_category',
            ]);
        } else {
            $item->fill([
                'latitude'       => null,
                'longitude'      => null,
                'location_label' => null,
                'precision_type' => null,
                'relevance_mode' => 'category_only',
            ]);
        }

        // The Marketplace has no reader-facing feed yet, so a listing is
        // accepted and kept rather than served into the news feed, where it
        // does not belong.
        if ($item->section === 'marketplace') {
            $item->status = 'held';
            $item->review_status = 'awaiting_marketplace';
        } elseif ($this->contributorIsTrusted($item) && $item->review_status === 'published') {
            $item->status = 'active';
        } else {
            // The automatic check says this is news. Whether it is TRUE, and
            // whether we want it on the site with our name on it, is a
            // different question and no model can answer it. So a contributor
            // nobody has vouched for waits for an editor - once, after which
            // they are no longer a stranger.
            $item->status = 'held';
            $item->review_status = 'pending_review';
        }

        $item->save();

        // The public address of a contributed story is a page on this site,
        // and it cannot be known before the row has an id.
        $url = url('/post/' . $item->id);

        if ($item->url !== $url) {
            $item->update(['url' => $url]);
        } elseif ($item->status === 'active') {
            // Nothing changed on the row, so the observer would not fire and
            // the story would never reach the feed. Touch it deliberately.
            $item->update(['updated_at' => now()]);
        }

        $this->storeTranslations($item, $verdict['translations']);

        return $item->refresh();
    }

    /**
     * Has an editor vouched for whoever wrote this?
     *
     * The queue exists to look at a new contributor's work, not to read the
     * same regular's copy for ever. Set CONTRIBUTIONS_ALWAYS_REVIEW=true to
     * keep every post waiting regardless.
     */
    private function contributorIsTrusted(NewsItem $item): bool
    {
        if (config('services.contributions.always_review')) {
            return false;
        }

        // Community Reports (owner's guide): a suitable report publishes at
        // once as "Unverified"; only a contributor whose credibility has
        // fallen to Restricted (below 20) waits for a person.
        if (config('services.community.enabled') && $item->contributor_id) {
            $credibility = \Illuminate\Support\Facades\DB::table('users')->where('id', $item->contributor_id)->value('credibility');

            if ($credibility !== null && (int) $credibility >= 20) {
                return true;
            }
        }

        if (!$item->contributor_id) {
            return false;
        }

        return User::where('id', $item->contributor_id)->whereNotNull('trusted_at')->exists();
    }

    /**
     * Keep the contributor's work, keep it out of the feed, and record why.
     */
    private function withhold(NewsItem $item, string $reviewStatus, string $why): NewsItem
    {
        $item->fill([
            'status'        => 'held',
            'review_status' => $reviewStatus,
            'review_reason' => mb_substr($why, 0, 300),
            'ai_status'     => $reviewStatus === 'rejected' ? 'discarded' : 'pending',
            'summary'       => $item->summary ?: mb_substr((string) $item->body, 0, 500),
        ]);

        $item->save();

        // 'pending' is the placeholder the row is created with, so an address
        // has to be written whether or not the column looks empty.
        $url = url('/post/' . $item->id);

        if ($item->url !== $url) {
            $item->update(['url' => $url]);
        }

        return $item->refresh();
    }

    /**
     * @param  array<string, array{title: string, summary: string}>  $translations
     */
    private function storeTranslations(NewsItem $item, array $translations): void
    {
        foreach ($translations as $locale => $text) {
            DB::table('news_translations')->updateOrInsert(
                ['news_item_id' => $item->id, 'locale' => $locale],
                [
                    'title'      => mb_substr($text['title'], 0, 550),
                    'summary'    => $text['summary'],
                    'model'      => 'deepseek-chat',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}

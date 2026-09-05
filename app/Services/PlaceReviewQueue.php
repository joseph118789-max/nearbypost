<?php

namespace App\Services;

use App\Models\NewsItem;
use App\Services\Geo\CountryCode;
use App\Services\Geo\LatinName;
use Illuminate\Support\Facades\DB;

/**
 * Places no map could identify, held for a person.
 *
 * The pipeline has three honest outcomes for a place, not two: found, found
 * somewhere else, and "a person needs to look at this". The third is not a
 * failure state - it is the correct answer for a launch described only by its
 * PT lot number, or a kampung too small for any gazetteer to carry. Local
 * knowledge settles most of these in seconds.
 *
 * One row per PLACE, not per story. The same unknown village arriving in five
 * stories is one question asked once, and answering it releases all five.
 */
class PlaceReviewQueue
{
    /** Why the last hold() was refused, or null if it was not. */
    private ?string $refusal = null;

    public function lastRefusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * Should a person be asked about this name at all?
     *
     * The queue exists for the kampung too small for a gazetteer and the
     * building known by a name nobody registered - things somebody who knows
     * the area settles in seconds. It was also holding a stadium in Chicago,
     * a hospital in Kathmandu, two airports written in Tamil script, the words
     * "Magistrates' Court here", and "Brian's home". Nineteen of 116. A person
     * cannot answer those, and every one of them was sitting above a real
     * question. Each kind has a better outcome than a human queue.
     */
    public static function refusalFor(string $placeText, ?string $home = 'my'): ?string
    {
        if (!LatinName::isUsable($placeText) && LatinName::latinForm($placeText) === null) {
            return 'not_latin';        // translate it first, then ask (a bracketed Latin name would have been used)
        }

        // Foreign to the SITE, not to the publisher: a Straits Times story
        // about Jurong East defaults to Singapore, and Singapore is abroad.
        // The polygon layer's reading of the text first: it knows that a
        // state name beats the publisher's country, so "Mersing, Johor" from
        // the Straits Times is Malaysian. The word-level test only decides
        // when the text names no state the layer knows.
        $country = (new \App\Services\Geo\Boundaries\BoundaryCheck())->expectation($placeText, $home)['country'] ?? null;
        $site    = \App\Services\Geo\SourceCountry::siteCountries();   // MY, SG, ... every country the site maps

        if ($country !== null) {
            if (!in_array(strtoupper((string) \App\Services\Geo\Boundaries\Iso3166::iso2($country)), $site, true)) {
                return 'foreign';      // national story; nobody here can be near it
            }
        } elseif (!in_array(strtoupper((string) CountryCode::forPlace($placeText, $home)), $site, true)) {
            return 'foreign';
        }

        if (preg_match('/\b(di sini|here|di situ|tempat kejadian)\b/iu', $placeText)) {
            return 'deictic';          // "here" is the dateline's job, not a person's
        }

        if (preg_match("/(\'s|s\')\s+(home|house|residence|apartment|room)\b|\bkediaman\b/iu", $placeText)) {
            return 'not_a_place';      // a private address is not a location to publish
        }

        return null;
    }

    /**
     * Hold a place for review. Returns false if it was already held OR if it
     * is not something a person can be asked; lastRefusal() says which.
     */
    public function hold(string $placeText, ?NewsItem $item, array $attempts = [], ?string $home = 'my', array $suggestions = []): bool
    {
        $this->refusal = null;
        $key = $this->key($placeText);

        if ($key === '') {
            return false;
        }

        if ($why = self::refusalFor($placeText, $home)) {
            $this->refusal = $why;

            return false;
        }

        $existing = DB::table('place_reviews')->where('place_key', $key)->first();

        if ($existing) {
            // Already answered? Then this story simply arrived after the
            // answer and should be placed, not queued again.
            //
            // And no counter here. It used to add one on every sighting, and
            // the geocoder re-sighted the same failed story every five
            // minutes - so one convention centre showed "602 stories waiting"
            // for four, and the page summed to 9,050 stories on a site that
            // held 3,700. The count is now read from the stories themselves;
            // see storyCounts().

            // A row holding no log, or somebody else's log, takes this one.
            // Rows written before the geocoder cleared its attempts between
            // places carried the previous place's searches, so the reviewer
            // saw a Mersing river mouth explained by Kota Tinggi.
            $stored = json_decode($existing->attempts ?? '[]', true) ?: [];
            $first  = mb_strtolower((string) ($stored[0]['query'] ?? ''));

            if ($attempts !== [] && ($stored === [] || !str_starts_with($first, mb_substr($key, 0, 12)))) {
                DB::table('place_reviews')->where('id', $existing->id)->update([
                    'attempts'   => json_encode($attempts),
                    'updated_at' => now(),
                ]);
            }

            if ($suggestions !== []) {
                DB::table('place_reviews')->where('id', $existing->id)->update(['suggestions' => json_encode($suggestions), 'updated_at' => now()]);
            }

            return false;
        }

        DB::table('place_reviews')->insert([
            'place_text'   => mb_substr($placeText, 0, 300),
            'place_key'    => $key,
            'news_item_id' => $item?->id,
            'story_title'  => $item ? mb_substr((string) $item->title, 0, 300) : null,
            'story_url'    => $item ? mb_substr((string) $item->url, 0, 600) : null,
            'source'       => $item ? mb_substr((string) $item->source, 0, 120) : null,
            'attempts'     => json_encode($attempts),
            'suggestions'  => json_encode($suggestions),
            'status'       => 'pending',
            'story_count'  => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return true;
    }

    /**
     * How many stories are actually waiting on each pending name.
     *
     * Counted from news_items, where the truth is, rather than kept in a
     * column that had to be maintained. Keyed by place_reviews.id.
     *
     * @return array<int, int>
     */
    public function storyCounts(): array
    {
        $key = "lower(regexp_replace(trim(regexp_replace(n.main_place_text, ',\\s*Malaysia\\s*$', '', 'i')), '\\s+', ' ', 'g'))";

        $rows = DB::select("
            select p.id, count(n.id) as n
            from place_reviews p
            left join news_items n on {$key} = p.place_key
            where p.status = 'pending'
            group by p.id");

        return array_column($rows, 'n', 'id');
    }

    /**
     * Record a person's answer, and make it stick.
     *
     * Three things have to happen together or the answer helps once and is
     * forgotten. The coordinate goes into the geocode cache, so the next story
     * naming this place is placed without anyone being asked again; every story
     * already waiting on it is released; and the review is closed.
     */
    public function resolve(int $id, float $lat, float $lng, ?string $label, ?string $note, string $who): int
    {
        $review = DB::table('place_reviews')->where('id', $id)->first();

        if (!$review) {
            return 0;
        }

        $label = $label ?: $review->place_text;

        DB::transaction(function () use ($review, $lat, $lng, $label, $note, $who) {
            DB::table('place_reviews')->where('id', $review->id)->update([
                'status'         => 'resolved',
                'lat'            => $lat,
                'lng'            => $lng,
                'resolved_label' => mb_substr($label, 0, 300),
                'note'           => $note,
                'resolved_by'    => mb_substr($who, 0, 120),
                'resolved_at'    => now(),
                'updated_at'     => now(),
            ]);

            // The answer becomes the cached answer. 'human' as the provider
            // matters: it is the one source in this pipeline that outranks
            // every automated one, and it should be visible as such.
            DB::table('geocode_cache')->updateOrInsert(
                ['place_key' => $review->place_key],
                [
                    'place_text' => mb_substr($review->place_text, 0, 250),
                    'lat'        => $lat,
                    'lng'        => $lng,
                    'label'      => mb_substr($label, 0, 250),
                    'confidence' => 0.95,
                    'provider'   => 'human',
                    'status'     => 'success',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        });

        return $this->releaseWaitingStories($review->place_text, $lat, $lng, $label);
    }

    /** Close a place that is not worth placing, without pretending to know it. */
    public function dismiss(int $id, ?string $note, string $who): void
    {
        DB::table('place_reviews')->where('id', $id)->update([
            'status'      => 'dismissed',
            'note'        => $note,
            'resolved_by' => mb_substr($who, 0, 120),
            'resolved_at' => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Place every story that was waiting on this name.
     *
     * Matched on the place text rather than the story that raised the review,
     * because by now several stories may name the same place and all of them
     * are owed the answer.
     */
    private function releaseWaitingStories(string $placeText, float $lat, float $lng, string $label): int
    {
        $stories = NewsItem::query()
            // 'held' is the state a story rests in while its name waits here,
            // so that the geocoder stops re-asking the map every five minutes.
            // A story placed meanwhile at a stand-in (another place it named)
            // is owed the exact answer as much as one still waiting.
            ->where(fn ($q) => $q->whereIn('geocode_status', ['failed', 'rate_limited', 'held'])
                ->orWhere('geocode_provider', 'like', 'proxy:%'))
            ->where(fn ($q) => $q->where('canonical_place_name', $placeText)
                ->orWhere('main_place_text', $placeText))
            ->get();

        foreach ($stories as $story) {
            $story->update([
                'latitude'           => $lat,
                'longitude'          => $lng,
                'lat'                => $lat,
                'lng'                => $lng,
                'geocode_status'     => 'success',
                'geocode_provider'   => 'human',
                'geocode_confidence' => 0.95,
                'geo_note'           => null,
                'geocoded_at'        => now(),
                // Cleared so the precision stage looks at it again rather than
                // leaving yesterday's verdict on a story that has just been
                // given a real position.
                'coverage_status'    => null,
                'coverage_type'      => null,
                'precision_type'     => null,
            ]);
        }

        return $stories->count();
    }

    /** The same normalisation the geocode cache uses, so the two agree. */
    private function key(string $placeText): string
    {
        $text = trim($placeText);
        $text = preg_replace('/\s*,\s*Malaysia\s*$/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_strtolower(trim($text));
    }
}

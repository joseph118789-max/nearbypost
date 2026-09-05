<?php

namespace App\Console\Commands;

use App\Models\FeedReadyItem;
use App\Models\NewsItem;
use App\Services\Geo\MalaysianStates;
use App\Services\Geo\PlaceScale;
use App\Services\GeocodingService;
use App\Services\Geo\SourceCountry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Serve a story at every place it happened, not just the first one.
 *
 * "Sabah nabs RM6.14 million in drugs in three August ops" reports three
 * seizures, in Kudat, Papar and Penampang. The model reads all three correctly
 * and marks each one "happened"; the pipeline then writes a single
 * main_place_text and the other two are lost. A reader in Penampang is not
 * shown a drugs raid in Penampang.
 *
 * Nothing new is needed to fix this, which is the point of this command being
 * short. Every piece already exists and is proven by the case-study panel:
 *
 *   story_locations   one row per place a story happened
 *   feed_ready_items  one serving row per place
 *   FeedQuery::nearby DISTINCT ON, so a reader sees the story once, at
 *                     whichever of its places is nearest to them
 *
 * All of it was wired to hand-authored case studies and to nothing else. This
 * connects the classifier to the same rails.
 */
class ServeMultiPointStories extends Command
{
    protected $signature = 'ingest:multipoint
        {--limit=100    : Stories to consider}
        {--news_item_id= : One story}
        {--dry-run      : Report what would be served, change nothing}';

    protected $description = 'Serve a story at every place it happened, not only the first';

    /**
     * More places than this and the story is about a region rather than a set
     * of locations - a nationwide operation reported district by district is
     * national news, and pinning it fifteen times would bury the feed.
     */
    private const MAX_PLACES = 6;

    /**
     * Closer than this and two names are one locality, not two events.
     * A venue sits inside its town; separate operations do not.
     */
    private const SAME_LOCALITY_KM = 15;

    /**
     * Beyond this from the rest of the story's places, a match is wrong rather
     * than distant. The Sabah drug seizures were 150km apart and are genuinely
     * one story; nothing real reaches eight hundred.
     */
    private const IMPLAUSIBLY_FAR_KM = 800;

    /** Malaysia, generously drawn, for checking a claim the text already made. */
    private const MY_BOUNDS = ['lat' => [0.5, 7.6], 'lng' => [99.0, 119.5]];


    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $stories = $this->candidates();

        if ($stories->isEmpty()) {
            $this->info('No multi-place stories waiting.');

            return 0;
        }

        $this->info(sprintf('%d stories name more than one place they happened.', $stories->count()));

        $geocoder = new GeocodingService();
        $served = 0;

        foreach ($stories as $story) {
            $labels = $this->happenedPlaces($story);

            if (count($labels) < 2) {
                continue;
            }

            $this->line(sprintf("\n  %s", mb_substr($story->title, 0, 72)));

            $placed = [];

            foreach ($labels as $label) {
                try {
                    // The bare name first. Borrowing the primary answer's state
                    // helps a small town - "Papar" alone is ambiguous - but it
                    // lies outright when the second place is in another state:
                    // a Kelantan match report naming Kuching produced "Kuching,
                    // Kelantan", and Kuching is in Sarawak. So the borrowed tail
                    // is now only a fallback for a name that cannot stand alone.
                    // Ask about nothing a reader could not be near. A
                    // pilgrims-return story listed "Malaysia" among its places;
                    // the geocoder matched the WORD to "Hewlett-Packard Malaysia
                    // Manufacturing Sdn Bhd" and served the story from a factory
                    // in Penang. Screening after the lookup was too late, because
                    // by then the name being screened was the factory's.
                    // Test the bare name as well as the whole label: this
                    // loop borrows the primary answer's tail, so "Malaysia"
                    // arrives as "Malaysia, Kuala Lumpur, Malaysia" and no
                    // longer looks like a bare country to the screen.
                    $bareLabel = trim(explode(',', $label)[0]);

                    if (PlaceScale::isTooBigToBeNear($label) || PlaceScale::isTooBigToBeNear($bareLabel)) {
                        $this->line(sprintf('    %-42s too big to be near, not served', mb_substr($label, 0, 42)));

                        continue;
                    }

                    $hit = $this->placeOf($geocoder, $label, SourceCountry::iso2($story->source));
                    $placed[] = ['label' => $hit['label'], 'lat' => $hit['lat'], 'lng' => $hit['lng']];
                    $this->line(sprintf('    %-42s %.4f, %.4f', mb_substr($hit['label'], 0, 42), $hit['lat'], $hit['lng']));
                } catch (\Throwable $e) {
                    // A place we cannot put on a map cannot be served from.
                    // Said out loud rather than dropped, because a story that
                    // quietly loses two of its three places looks correct.
                    $this->warn(sprintf('    %-42s not found, will not be served', mb_substr($label, 0, 42)));
                }
            }

            $placed = $this->dropNestedPlaces(
                $this->dropImplausiblyFar($this->dropContainingStates($placed))
            );

            if (count($placed) < 2) {
                $this->line('    one locality only - left as an ordinary story');
                continue;
            }

            if (!$dry) {
                $this->serve($story, $placed);
            }

            $served++;
        }

        $this->newLine();
        $this->info(sprintf('%s %d stories at multiple places.', $dry ? 'Would serve' : 'Serving', $served));

        return 0;
    }

    /**
     * Stories the classifier judged, whose reasoning names more than one place
     * where something happened.
     */
    private function candidates()
    {
        $query = NewsItem::query()
            ->where('ai_status', 'success')
            ->where('relevance_mode', 'location_and_category')
            ->where('is_multi_point', false)
            ->whereNotNull('place_roles')
            // Cheap pre-filter in SQL; the exact count is done in PHP where the
            // json is easier to read than to query.
            ->whereRaw("place_roles::text like '%happened%'");

        if ($id = $this->option('news_item_id')) {
            $query = NewsItem::query()->where('id', $id);
        }

        return $query->orderByDesc('published_at')->limit((int) $this->option('limit'))->get();
    }

    /**
     * The places the model said the story happened in, written so a gazetteer
     * can find them.
     *
     * The model returns a place as the article writes it - "Papar", not "Papar,
     * Sabah" - because that is what the text says. On its own that is ambiguous
     * to a map. The primary answer usually carries the wider place, so its tail
     * is borrowed: "Kudat, Sabah" lends "Sabah" to "Papar", which is the
     * difference between the right district and a guess.
     */
    private function happenedPlaces(NewsItem $story): array
    {
        $roles = is_string($story->place_roles)
            ? json_decode($story->place_roles, true)
            : (array) $story->place_roles;

        $tail = '';

        if ($story->main_place_text && str_contains($story->main_place_text, ',')) {
            $parts = array_map('trim', explode(',', $story->main_place_text));
            $tail = ', ' . end($parts);
        }

        $out = [];

        foreach ((array) $roles as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'happened') {
                continue;
            }

            $place = trim((string) ($entry['p'] ?? ''));

            if ($place === '') {
                continue;
            }

            // Already carries its wider place, or we have none to lend.
            $label = str_contains($place, ',') || $tail === ''
                ? $place
                : $place . $tail;

            $out[mb_strtolower($label)] = $label;
        }

        // The specificity ladder means a story can name a venue and the town it
        // stands in, both marked happened. Those are one place, not two, and
        // the narrower already contains the wider.
        $out = $this->dropPlacesContainedInOthers($out);

        $out = array_values($out);

        // A story naming more places than this is reporting one measurement at
        // each - an air quality table, exam results by district - not several
        // events. Truncating to six would serve a national bulletin at whichever
        // six the reporter happened to type first.
        if (count($out) > self::MAX_PLACES) {
            $this->line(sprintf(
                '    %d places named - a bulletin, not a set of events; left national',
                count($out)
            ));

            return [];
        }

        return $out;
    }

    /**
     * "Kudat" and "Kudat, Sabah" are the same place said twice. Serving both
     * would put two pins on one town.
     */
    private function dropPlacesContainedInOthers(array $labels): array
    {
        $kept = [];

        foreach ($labels as $key => $label) {
            $redundant = false;

            foreach ($labels as $otherKey => $other) {
                if ($key === $otherKey) {
                    continue;
                }

                // The other names this one and more besides.
                if (str_contains($otherKey, $key) && mb_strlen($otherKey) > mb_strlen($key)) {
                    $redundant = true;
                    break;
                }
            }

            if (!$redundant) {
                $kept[$key] = $label;
            }
        }

        return $kept;
    }

    /**
     * Resolve a place, preferring what the article actually called it.
     *
     * The bare name is tried first, because it is what the text said. Only a
     * name the gazetteer cannot place on its own borrows the state from the
     * primary answer - which is the case the borrowing was added for, and the
     * only case where it is safe.
     */
    private function placeOf(GeocodingService $geocoder, string $label, ?string $home = 'my'): array
    {
        $bare = trim(explode(',', $label)[0]);

        // The text said which state it is in, so a bare-name match outside
        // Malaysia contradicts the article. "Quadrangle, Pulau Pinang" reduced
        // to "Quadrangle" found a place in Victoria, Australia.
        $mustBeMalaysian = $this->namesAMalaysianState($label);

        if ($bare !== $label && $bare !== '') {
            try {
                $hit = $geocoder->geocode($bare, $home);

                if (!$mustBeMalaysian || $this->inMalaysia($hit['lat'], $hit['lng'])) {
                    // array_merge, not "+". The geocoder's result ALREADY has a
                    // label, and "+" keeps the left side's keys - so "$hit +
                    // ['label' => $bare]" silently threw away the name we meant
                    // and served the geocoder's instead. That is how a story
                    // came to be labelled "Hewlett-Packard Malaysia
                    // Manufacturing Sdn Bhd", and how the too-big screen below
                    // ended up testing that string rather than "Malaysia".
                    return array_merge($hit, ['label' => $bare]);
                }

                $this->line(sprintf(
                    '    %-42s bare name matched outside Malaysia, using the full label',
                    mb_substr($bare, 0, 42)
                ));
            } catch (\Throwable $e) {
                // Not findable alone. The borrowed state earns its place.
            }
        }

        $hit = $geocoder->geocode($label, $home);

        return array_merge($hit, ['label' => $label]);
    }

    /** Does the label say which Malaysian state this is in? */
    private function namesAMalaysianState(string $label): bool
    {
        foreach (array_map('trim', explode(',', $label)) as $part) {
            if (MalaysianStates::isBareState($part)) {
                return true;
            }
        }

        return false;
    }

    private function inMalaysia(float $lat, float $lng): bool
    {
        return $lat >= self::MY_BOUNDS['lat'][0] && $lat <= self::MY_BOUNDS['lat'][1]
            && $lng >= self::MY_BOUNDS['lng'][0] && $lng <= self::MY_BOUNDS['lng'][1];
    }

    /**
     * Drop anything sitting absurdly far from the rest of the story.
     *
     * Measured against the first place, which is the narrowest the model named
     * and the one it was most sure of. A backstop rather than a rule: it
     * catches the bad matches nobody predicted, including in countries this
     * pipeline has never seen.
     */
    private function dropImplausiblyFar(array $placed): array
    {
        if (count($placed) < 2) {
            return $placed;
        }

        $anchor = $placed[0];
        $kept = [];

        foreach ($placed as $p) {
            $distance = $this->km($anchor, $p);

            if ($distance > self::IMPLAUSIBLY_FAR_KM) {
                $this->line(sprintf(
                    '    %-42s %.0f km from %s - a bad match, dropped',
                    mb_substr($p['label'], 0, 42),
                    $distance,
                    $anchor['label']
                ));

                continue;
            }

            $kept[] = $p;
        }

        return $kept;
    }

    /**
     * Drop the state when the story also names places inside it.
     *
     * Kept when it is all there is: a story reporting only that Sarawak is
     * affected still belongs to Sarawak, and the coverage rules downstream
     * decide whether a place that large can be served at all.
     */
    private function dropContainingStates(array $placed): array
    {
        $narrow = array_values(array_filter(
            $placed,
            fn ($p) => !PlaceScale::isTooBigToBeNear($p['label'])
        ));

        // Nothing narrower was named, so the state is the answer.
        if (count($narrow) < 2) {
            return $placed;
        }

        foreach ($placed as $p) {
            if (PlaceScale::isTooBigToBeNear($p['label'])) {
                $this->line(sprintf(
                    '    %-42s the state containing the others, dropped',
                    mb_substr($p['label'], 0, 42)
                ));
            }
        }

        return $narrow;
    }

    /**
     * Three pins on one town is not a multi-point story.
     *
     * The specificity ladder asks the model to name a venue AND the place it
     * stands in, and both are truthfully where it happened. So a boy falling
     * from Victoria Bridge came back as Victoria Bridge, Enggor and Kuala
     * Kangsar - one event at three zoom levels, 600 metres and 8 kilometres
     * apart. Serving that three times would tell a reader three things
     * happened.
     *
     * Comparing the words cannot separate this from the real thing: Papar
     * and Penampang look no more distinct than Enggor and Kuala Kangsar.
     * Distance can. Separate events are separate places - the Sabah seizures
     * are 150km apart - while a venue and its district are on top of each
     * other.
     *
     * Within the radius, the first listed wins: the contract asks for places in
     * the order the text names them, and the ladder names the narrowest first.
     */
    private function dropNestedPlaces(array $placed): array
    {
        $kept = [];

        foreach ($placed as $candidate) {
            $nested = false;

            foreach ($kept as $already) {
                if ($this->km($already, $candidate) < self::SAME_LOCALITY_KM) {
                    $nested = true;
                    $this->line(sprintf(
                        '    %-42s same locality as %s, dropped',
                        mb_substr($candidate['label'], 0, 42),
                        $already['label']
                    ));
                    break;
                }
            }

            if (!$nested) {
                $kept[] = $candidate;
            }
        }

        return $kept;
    }

    /** Great-circle distance in kilometres. */
    private function km(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }

    /**
     * Record the places and serve one feed row for each.
     *
     * Written wholesale rather than merged, and inside a transaction: a story
     * half-converted to multi-point is worse than one left alone, because
     * is_multi_point tells the observer to stop maintaining its feed rows.
     */
    private function serve(NewsItem $story, array $placed): void
    {
        // one row per spot: four names that resolved to the same coordinates are one place
        $seen = [];
        $placed = array_values(array_filter($placed, function ($p) use (&$seen) {
            $key = round((float) $p['lat'], 4) . ',' . round((float) $p['lng'], 4);
            if (isset($seen[$key])) { return false; }
            $seen[$key] = true;
            return true;
        }));

        if ($placed === []) {
            return;
        }

        DB::transaction(function () use ($story, $placed) {
            DB::table('story_locations')->where('news_item_id', $story->id)
                ->where('added_by', 'ai')->delete();

            foreach ($placed as $place) {
                DB::table('story_locations')->insert([
                    'news_item_id'   => $story->id,
                    'label'          => mb_substr($place['label'], 0, 250),
                    'lat'            => $place['lat'],
                    'lng'            => $place['lng'],
                    'added_by'       => 'ai',
                    'geocode_status' => 'found',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            $story->update([
                // From here the observer leaves the feed rows alone, so they
                // are this command's responsibility.
                'is_multi_point'  => true,
                'main_place_text' => $placed[0]['label'],
                'location_label'  => $placed[0]['label'],
                // The third name for the same thing, and the one the feed
                // builder actually reads. Leaving it stale is a documented
                // trap in this codebase and it caught this command too.
                'canonical_place_name' => $placed[0]['label'],
                'latitude'        => $placed[0]['lat'],
                'longitude'       => $placed[0]['lng'],
                'lat'             => $placed[0]['lat'],
                'lng'             => $placed[0]['lng'],
            ]);

            FeedReadyItem::where('news_item_id', $story->id)->delete();

            foreach ($placed as $i => $place) {
                FeedReadyItem::create([
                    'news_item_id'       => $story->id,
                    // The lists that show a story once (By Interest, a country's latest)
                    // keep the primary row only; the map gets every place.
                    'is_primary_location' => $i === 0,
                    'title'              => $story->ai_title ?: $story->title,
                    'summary'            => $story->ai_summary ?: $story->summary,
                    'source'             => $story->source,
                    'url'                => $story->url,
                    'published_at'       => $story->published_at,
                    'primary_category'   => $story->ai_category ?: $story->primary_category,
                    'secondary_category' => $story->secondary_category,
                    'sub_category'       => $story->sub_category,
                    'location_label'     => $place['label'],
                    'lat'                => $place['lat'],
                    'lng'                => $place['lng'],
                    'origin'             => $story->origin ?: 'scraper',
                    'image_path'         => $story->image_path,
                    'is_active'          => true,
                    // The feed filters on both of these. Without them the rows
                    // exist, look right in the table, and are served to nobody -
                    // which is exactly how this was first written.
                    'is_article'         => true,
                    'relevance_mode'     => 'location_and_category',
                ]);
            }
        });

        Log::info('Story served at multiple places', [
            'news_item_id' => $story->id, 'places' => count($placed),
        ]);

        $this->line(sprintf('    serving at %d places', count($placed)));
    }
}

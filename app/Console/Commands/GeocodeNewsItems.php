<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Services\Geo\Boundaries\BoundaryStore;
use App\Services\Geo\GeoPlausibility;
use App\Services\Geo\SourceCountry;
use App\Services\Geo\ClaimCountry;
use App\Services\Geo\PlaceScale;
use App\Services\Geo\ProxyPlaces;
use App\Services\Geo\QualifiedName;
use App\Services\PlaceReviewQueue;
use App\Services\GeocodingService;
use App\Services\GeocodingException;
use App\Services\GeocodingRateLimitedException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Geocode news items that have a canonical_place_name.
 * Skips category_only items cleanly.
 * Bounded retry for rate limits.
 */
class GeocodeNewsItems extends Command
{
    protected $signature = 'ingest:geocode
        {--news_item_id= : Process a specific news item}
        {--limit=50     : Max items to process per run}';

    protected $description = 'Geocode canonical_place_name → lat/lng (skips category_only items)';

    private const MAX_RATE_LIMIT_RETRIES = 2;
    private const RATE_LIMIT_SLEEP_SECS  = 5;

    private GeocodingService $geocoder;

    public function __construct()
    {
        parent::__construct();
        $this->geocoder = new GeocodingService();
    }

    public function handle(): int
    {
        $newsItemId = $this->option('news_item_id');
        $limit     = (int) $this->option('limit');

        $query = NewsItem::query()
            ->whereNotNull('canonical_place_name')
            // Alias matching normalises names; it does not decide what gets
            // geocoded. 'passthrough' rows carry usable raw place text.
            ->whereIn('alias_match_status', ['matched', 'passthrough'])
            ->where(function ($q) {
                // Not yet geocoded, or previously failed (allow retry)
                // 'held': the name is with a person, and asking the map again
                // every five minutes changes nothing (it did, 380 times).
                $q->whereNull('geocode_status')
                  ->orWhereNotIn('geocode_status', ['success', 'held', 'unplaceable'])
                  // 'unplaceable' was terminal, and it should not have been.
                  //
                  // ⛔ On 3 Sep 2026, 24 foreign places - Zandvoort, Shanghai,
                  // Madrid, Melbourne - were all written off in one 5am batch
                  // because the public map was briefly unreachable. Retried by
                  // hand a day later every one of them resolved. A minute's
                  // outage should not cost a story its pin for ever.
                  //
                  // So: one more attempt, but only after six hours, and only
                  // while the story is still young enough to be worth serving.
                  // A name nobody can place stays unplaceable after that.
                  ->orWhere(function ($r) {
                      $r->where('geocode_status', 'unplaceable')
                        ->where('geocoded_at', '<', now()->subHours(6))
                        ->where('created_at', '>', now()->subDays(3));
                  });
            })
            ->where('relevance_mode', '!=', 'category_only')  // explicit skip
            ;

        if ($newsItemId) {
            $query->where('id', $newsItemId);
        }

        // Newest first: a stage that cannot clear its backlog should
        // spend its limit on today's news, not on the same old stories
        // that have failed every run for months.
        $items = $query->orderByDesc('published_at')->limit($limit)->get();
        $this->info("Geocoding: {$items->count()} items (provider={$this->geocoder->getProvider()}).");

        $rateLimited = 0;
        foreach ($items as $item) {
            $result = $this->geocodeItem($item);
            if ($result === 'rate_limited') $rateLimited++;
            // Stop if we've hit too many rate limits in a row
            if ($rateLimited >= 3) {
                $this->warn('Stopping: too many consecutive rate-limit responses.');
                break;
            }
        }

        $this->info('Done.');
        return 0;
    }

    private function geocodeItem(NewsItem $item): string
    {
        $placeName = $item->canonical_place_name;

        // a non-Latin name with its Latin name in brackets is asked for by the Latin name
        if (!\App\Services\Geo\LatinName::isUsable($placeName)) {
            // the Latin name in brackets, or beside the script name in the
            // story's own place notes, or failing both the model's reading
            // given those notes
            $rolesText = is_string($item->place_roles) ? $item->place_roles : json_encode($item->place_roles, JSON_UNESCAPED_UNICODE);
            $latin = \App\Services\Geo\LatinName::latinForm($placeName)
                ?? \App\Services\Geo\LatinName::fromContext($placeName, $rolesText)
                ?? \App\Services\Geo\LatinName::viaModel($placeName, $rolesText);

            if ($latin !== null) {
                $this->line("  latin form: {$placeName} -> {$latin}");
                $placeName = $latin;
            }
        }

        // "Sessions Court here": the word "here" points at the dateline, and
        // the dateline is on the story's place roles. Resolved at enrichment
        // when it works; resolved again here for the stories where it did not.
        if (preg_match('/\b(here|di sini|di situ)\b/iu', (string) $placeName)) {
            $corr = [];
            $roles = is_string($item->place_roles) ? json_decode($item->place_roles, true) : $item->place_roles;
            $resolved = \App\Services\Geo\DeicticPlace::resolve($placeName, $roles, $corr);

            if ($resolved !== null && $resolved !== $placeName) {
                $this->line("  deictic: {$placeName} -> {$resolved}");
                $placeName = $resolved;
            }
        }

        // Whatever happens below, the story belongs to a country. Written
        // first, so even a story that is skipped or held is counted
        // somewhere; overwritten with the pin's country on success.
        if ($item->geo_claim_country === null) {
            $claim = ClaimCountry::of($placeName ?: $item->main_place_text, $item->source, $item->geo_country_code);

            if ($claim !== null) {
                $item->update(['geo_claim_country' => $claim]);
            }
        }

        // ── Skip category_only items explicitly ─────────────────────────
        if ($item->relevance_mode === 'category_only') {
            $item->update([
                'geocode_status' => 'skipped_category_only',
                'geocoded_at'    => now(),
            ]);
            $this->line("  SKIP-cat_only {$item->id}: {$placeName}");
            return 'skipped';
        }

        // ── Already geocoded successfully ────────────────────────────────
        if ($item->geocode_status === 'success') {
            $this->line("  SKIP-done {$item->id}: already geocoded");
            return 'skipped';
        }

        // A country or a state is not a place a reader can be near, and asking
        // about one is how "Malaysia" came back as Hewlett-Packard Malaysia
        // Manufacturing Sdn Bhd in Penang - Nominatim matched the WORD inside a
        // company name and answered with a factory. The enricher already nulls
        // these; this is the net under the stages that write here afterwards.
        $venue = trim(explode(',', (string) $placeName)[0]);

        // the VENUE, not its country ("Sephora Ion Orchard, Singapore" is a shop), and only a
        // venue of one or two words can BE a country or a state ("Jalan Masjid Kapitan Keling and
        // Lebuh China junction" carries the word China and is a street corner in George Town)
        if (count(preg_split('/\s+/', $venue)) <= 2 && PlaceScale::isTooBigToBeNear($venue)) {
            // A country, a state, a border between two countries: nowhere a
            // reader can be near, so no pin - but the story is not gone. It
            // is national, found under its topic. Leaving relevance_mode as
            // location_and_category with no pin made it invisible: the feed
            // gate refuses a located story without coordinates.
            $item->update([
                'geocode_status' => 'skipped_too_big',
                'relevance_mode' => 'category_only',
                'geocoded_at'    => now(),
            ]);
            $this->line("  SKIP-too-big {$item->id}: {$placeName}");

            return 'skipped';
        }

        // WHICH MAP TO ASK. Normally the masthead's country, which is right
        // almost always and is the whole reason local stories resolve well.
        //
        // The exception is a paper that covers somewhere else: China Press is
        // Malaysian and reports on China daily. Asking the Malaysian map for a
        // Chinese city does not fail - it answers, with whatever Malaysian
        // thing carries that name, and the answer passes every downstream
        // check because the point really is in Malaysia. So ask the name first
        // whether it could be in this country at all.
        $home = SourceCountry::iso2($item->source);
        $implied = (new \App\Services\Geo\ChainCheck())->impliedHome($placeName, $home);

        if ($implied !== null) {
            $this->line("  no such place in {$home}: asking the {$implied} map instead");
            $home = $implied;
        }

        $retries = 0;
        $lastException = null;

        while ($retries <= self::MAX_RATE_LIMIT_RETRIES) {
            try {
                $result = $this->geocoder->geocode($placeName, $home);

                // A geocoder never says "I don't know". It loosens the search
                // until something matches, so a name it cannot place comes back
                // as somewhere else entirely - "Victoria Bridge, Kuala Kangsar,
                // Perak" came back as Manchester, and was mapped without
                // complaint because nothing compared the answer to the question.
                $contradiction = GeoPlausibility::contradiction(
                    $placeName,
                    (float) $result['lat'],
                    (float) $result['lng']
                )
                // The mirror image: a foreign name answered with coordinates in
                // another country entirely. The Malaysia test above cannot see
                // it, because the name is not Malaysian.
                ?? GeoPlausibility::wrongCountry($placeName, $result['country'] ?? null)
                // And the version that needs no country from the provider, for
                // the paths that do not return one.
                ?? GeoPlausibility::foreignNameInMalaysia(
                    $placeName,
                    (float) $result['lat'],
                    (float) $result['lng']
                );

                if ($contradiction !== null) {
                    throw new GeocodingException($contradiction);
                }

                // A bare name is not one place - Malaysia has several
                // Sungai Siputs, and "Kota Baru" alone resolves to Kotabaru in
                // Indonesia. The classifier is asked for the state and forgets;
                // measured, it ranged from 86% of names to 8% after one wording
                // change. Nominatim just told us the state, so the name is
                // completed from the answer instead of asked for again.
                $qualified = QualifiedName::complete(
                    (string) $placeName,
                    $result['state'] ?? null,
                    $result['country'] ?? null
                );

                if ($qualified !== $placeName) {
                    $this->line("  qualified {$item->id}: {$placeName} -> {$qualified}");
                }

                $item->update([
                    'canonical_place_name' => $qualified,
                    'latitude'           => $result['lat'],
                    'longitude'          => $result['lng'],
                    // Keep the legacy pair in step: a geocoded fix is
                    // authoritative over any coordinate the AI guessed.
                    'lat'                => $result['lat'],
                    'lng'                => $result['lng'],
                    'geocode_status'     => 'success',
                    'geocode_provider'   => $this->geocoder->lastProvider(),
                    'geocode_confidence' => $result['confidence'],
                    'geo_note'           => $result['note'] ?? null,
                    'geocoded_at'        => now(),
                ] + (isset($result['precision']) ? ['precision_type' => $result['precision']] : []));

                // Where the point actually is, as codes. Not what the text
                // said - what the polygons say. A state filter, a per-state
                // count, or a "wrong state" alarm reads these, never the text.
                $at = (new BoundaryStore())->locate((float) $result['lat'], (float) $result['lng']);

                if ($at['country'] !== null) {
                    $item->update(['geo_country_code' => $at['country'], 'geo_state_code' => $at['state'], 'geo_city_code' => $at['city'] ?? null, 'geo_claim_country' => $at['country']]);
                }

                $this->line("  OK {$item->id} | {$placeName} → [{$result['lat']},{$result['lng']}]");

                // If a person was about to be asked this, they no longer need
                // to be. The improved cascade answered it.
                $closed = DB::table('place_reviews')
                    ->where('place_key', mb_strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*,\s*Malaysia\s*$/i', '', $placeName)))))
                    ->where('status', 'pending')
                    ->update([
                        'status'      => 'dismissed',
                        'note'        => 'Placed by the cascade (' . $this->geocoder->lastProvider() . ') before anyone was asked.',
                        'resolved_by' => 'cascade',
                        'resolved_at' => now(),
                        'updated_at'  => now(),
                    ]);

                if ($closed) {
                    $this->line("  released a review nobody needs to answer now");
                }
                Log::info('Geocode success', [
                    'news_item_id' => $item->id,
                    'place'        => $placeName,
                    'lat'          => $result['lat'],
                    'lng'          => $result['lng'],
                ]);
                return 'success';

            } catch (GeocodingRateLimitedException $e) {
                $retries++;
                $lastException = $e;
                Log::warning('Geocode rate limited', [
                    'news_item_id' => $item->id,
                    'attempt'     => $retries,
                    'place'       => $placeName,
                ]);
                if ($retries > self::MAX_RATE_LIMIT_RETRIES) break;
                $sleep = self::RATE_LIMIT_SLEEP_SECS * $retries;
                $this->warn("  RATE-LIMITED {$item->id}: sleeping {$sleep}s before retry...");
                sleep($sleep);

            } catch (GeocodingException $e) {
                $lastException = $e;
                break; // Don't retry non-rate-limit errors
            }
        }

        // ── Failed after retries ─────────────────────────────────────────
        $item->update([
            'geocode_status'  => $lastException instanceof GeocodingRateLimitedException ? 'rate_limited' : 'failed',
            'geocode_provider'=> $this->geocoder->lastProvider(),
            'geocoded_at'     => now(),
        ]);

        // A rate limit says nothing about the place - it says the server was
        // busy, and the next run will ask again. Only a genuine miss, after
        // every door has been tried, is worth a person's time.
        if (!$lastException instanceof GeocodingRateLimitedException) {
            $queue = new PlaceReviewQueue();
            $held  = $queue->hold($placeName, $item, $this->geocoder->lastAttempts(), $home, $this->geocoder->lastSuggestions());

            if ($why = $queue->lastRefusal()) {
                // "Sessions Court here" and "Brian's home, Kuala Lumpur" are
                // not questions for a person, but they are not the end
                // either: the story itself names the court, the road, the
                // hospital. The stand-in step re-reads the story for a NAME
                // before the story is given up on. (Owner, 3 Sep: use what
                // was learned on the no-pin stories.)
                if (in_array($why, ['deictic', 'not_a_place', 'not_latin'], true) && $this->placeAtProxy($item, $placeName)) {
                    $this->line("  STAND-IN for a {$why} name {$item->id}: {$placeName}");

                    return 'success';
                }

                // Not a question for a person. Each kind has its own right
                // outcome, and all of them end the five-minute retry.
                $update = ['geocode_status' => 'unplaceable', 'geocoded_at' => now()];

                if ($why === 'foreign') {
                    // A Malaysian rider winning at Brno is national news. A
                    // reader in Brno is not more interested than one in Ipoh,
                    // so the story has no point on the map - it is found under
                    // its topic, by everyone. Pinning the venue was never
                    // possible; pinning something ELSE (the embassy) was the
                    // bug.
                    $update['relevance_mode'] = 'category_only';
                }

                $item->update($update);
                $this->line("  UNPLACEABLE ({$why}) {$item->id}: {$placeName}");

                return 'failed';
            }

            // Held, or already waiting on the same name: either way a person
            // has it, and the map need not be asked again until they answer.
            $item->update(['geocode_status' => 'held', 'geocoded_at' => now()]);
            $this->line(($held ? '  HELD FOR REVIEW ' : '  waiting on a name already held ') . "{$item->id}: {$placeName}");

            // Meanwhile: the story is not left off the map while it waits.
            // Another place the SAME story names - the hospital the injured
            // went to, the fire station that answered - stands in for the
            // junction no index knows. The person's exact answer replaces it.
            return $this->placeAtProxy($item, $placeName) ? 'success' : 'failed';
        }

        $this->warn("  FAILED {$item->id}: {$placeName} — " . $lastException?->getMessage());
        Log::warning('Geocode failed', [
            'news_item_id' => $item->id,
            'place'        => $placeName,
            'error'        => $lastException?->getMessage(),
        ]);
        return 'failed';
    }

    /**
     * Place a held story at an alternative place it names. See ProxyPlaces.
     * The review stays open with the stand-in shown on its card; the story
     * goes live now, at 'approximate_area' precision, and says on itself
     * where it was put and why.
     */
    private function placeAtProxy(NewsItem $item, string $placeName): bool
    {
        $proxy = new ProxyPlaces($this->geocoder);

        try {
            $p = $proxy->resolve($item, $placeName, SourceCountry::iso2($item->source));
        } catch (GeocodingRateLimitedException $e) {
            // The map was busy, not silent. Back to 'failed' so the next
            // five-minute run asks again instead of resting for good.
            $item->update(['geocode_status' => 'failed']);
            $this->warn("  proxy search rate-limited for {$item->id}; will retry");

            return false;
        }

        $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*,\s*Malaysia\s*$/i', '', $placeName))));

        if ($p === null) {
            // Leave the search log on the card so the person sees what the
            // story itself offered and why none of it could be used.
            $review = DB::table('place_reviews')->where('place_key', $key)->where('status', 'pending')->first();

            if ($review) {
                $stored = json_decode($review->attempts ?? '[]', true) ?: [];
                DB::table('place_reviews')->where('id', $review->id)->update([
                    'attempts' => json_encode(array_merge($stored, $proxy->attempts())), 'updated_at' => now()]);
            }

            $this->line("  no stand-in for {$item->id}: the story names nothing else the map knows");

            return false;
        }

        $note = $p['tier'] === 'model_coordinates'
            ? sprintf('Coordinates proposed by the model for "%s" and accepted only because they fall inside the state, near the district, and reverse-look-up in the right town. Typically 1-3 km out; a person can still give the exact spot.', $placeName)
            : sprintf('Placed at %s, which the story names as %s (%s). "%s" itself is on no map; a person can still give the exact spot.',
                $p['name'], $this->roleWords($p['role']), $p['why'] ?: 'no reason given', $placeName);

        $item->update([
            'latitude'           => $p['lat'],
            'longitude'          => $p['lng'],
            'lat'                => $p['lat'],
            'lng'                => $p['lng'],
            'geocode_status'     => 'success',
            'geocode_provider'   => 'proxy:' . $p['provider'],
            'geocode_confidence' => $p['tier'] === 'model_coordinates' ? 0.3 : 0.5,
            'precision_type'     => 'approximate_area',
            'geo_note'           => $note,
            'geocoded_at'        => now(),
        ]);

        $at = (new BoundaryStore())->locate($p['lat'], $p['lng']);

        if ($at['country'] !== null) {
            $item->update(['geo_country_code' => $at['country'], 'geo_state_code' => $at['state'], 'geo_city_code' => $at['city'] ?? null, 'geo_claim_country' => $at['country']]);
        }

        DB::table('place_reviews')->where('place_key', $key)->where('status', 'pending')->update([
            'placed_at'  => json_encode(['name' => $p['name'], 'role' => $p['role'], 'why' => $p['why'], 'lat' => round($p['lat'], 6), 'lng' => round($p['lng'], 6),
                'label' => $p['label'], 'provider' => $p['provider'], 'tier' => $p['tier'], 'news_item_id' => $item->id]),
            'updated_at' => now(),
        ]);

        $this->line(sprintf("  PLACED NEARBY %d: at %s (%s, via %s) [%.5f,%.5f]", $item->id, $p['name'], $p['role'], $p['provider'], $p['lat'], $p['lng']));
        Log::info('Geocode placed at proxy', ['news_item_id' => $item->id, 'place' => $placeName, 'proxy' => $p['name'], 'role' => $p['role'], 'tier' => $p['tier']]);

        return true;
    }

    private function roleWords(string $role): string
    {
        return match ($role) {
            'happened'  => 'another place where it happened',
            'aftermath' => 'where its aftermath went',
            'office'    => 'an office that acted in it',
            'origin'    => 'where those involved came from',
            default     => 'a place in the story',
        };
    }
}

<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\DB;

/**
 * Paid placements in a result list. Spec 15.4, and the ranking rules in 15.3.
 *
 * ⛔⛔ SPONSORSHIP MOVES A RESULT. IT NEVER CHANGES WHAT THE RESULT SAYS.
 *
 * Spec 15.4: "Preserve truthful distance and verification labels. Do not
 * disguise sponsored results as editorial recommendation."
 *
 * The obvious way to build this is the wrong way: query sponsored listings
 * separately and merge them in. Do that and their distance is computed by a
 * second piece of code, and the day those two disagree the paid result is the
 * one that lies - showing "1.2 km" for a shop across the state, which is
 * exactly the deception 15.4 exists to prevent.
 *
 * So a sponsored candidate is fetched THROUGH ListingSearch::near(), the same
 * call, the same visibility predicate, the same distance SQL, just filtered to
 * a set of listing ids. Consequences, all of them wanted:
 *
 *   - a sponsored listing outside the search radius simply does not come back,
 *     because the radius test is the same one. Paying cannot make a shop near;
 *   - a suspended, expired or unmoderated listing cannot be bought into view,
 *     because publication and moderation are in that same predicate;
 *   - the distance shown is the distance computed. There is no second number.
 *
 * This class then reorders, labels, and records what was shown. It never edits
 * a field of a listing it did not create.
 */
class SponsoredPlacements
{
    /**
     * Most paid slots on one page of results.
     *
     * Spec 15.4 says "capped" without saying where, so: two. A reader scanning
     * a list of forty will accept two marked results as the cost of a free
     * service; a list that opens with six has stopped being a search and become
     * an advertisement, and the reader stops believing the other thirty-four.
     */
    private const MAX_SLOTS = 2;

    /**
     * And never more than this share of what is actually on the page.
     *
     * The absolute cap alone is not enough. Two sponsored results out of forty
     * is an eighth of the first screen; two out of three is the whole page.
     * Thin result sets are exactly where a sponsor most wants to be and where
     * a reader can least afford to be misled.
     */
    private const MAX_SHARE = 0.25;

    /**
     * Decorate an organic result list with paid placements.
     *
     * @param  list<array<string, mixed>>  $organic  straight from ListingSearch::near()
     * @param  array{category_id?: int, record?: bool}  $opts
     * @return list<array<string, mixed>>
     */
    public static function decorate(
        array $organic,
        float $lat,
        float $lng,
        float $radiusKm,
        string $country,
        array $opts = []
    ): array {
        $country = strtoupper($country);

        // Everything below still marks the organic rows, so a template can
        // rely on the key existing whether or not advertising is on anywhere.
        if ($organic === [] || FeatureFlags::off('sponsored_listings_enabled', $country)) {
            return self::mark($organic);
        }

        $categoryId = isset($opts['category_id']) ? (int) $opts['category_id'] : null;
        $campaigns  = Advertising::live($country, $categoryId);

        if ($campaigns === []) {
            return self::mark($organic);
        }

        // A campaign may name a listing, or sponsor the provider generally. A
        // general one is resolved to that provider's listings so that from here
        // on everything is a listing id and the search does the rest.
        $byListing = [];

        foreach ($campaigns as $c) {
            if (!self::reaches($c, $lat, $lng)) {
                continue;
            }

            $ids = $c->listing_id !== null
                ? [(int) $c->listing_id]
                : DB::table('marketplace_listings')
                    ->where('provider_profile_id', $c->provider_profile_id)
                    ->whereNull('deleted_at')
                    ->pluck('id')->map(fn ($i) => (int) $i)->all();

            foreach ($ids as $id) {
                // First campaign to claim a listing keeps it; two campaigns on
                // one listing is still one slot.
                $byListing[$id] ??= $c;
            }
        }

        if ($byListing === []) {
            return self::mark($organic);
        }

        // ⛔ THE SAME CALL THE ORGANIC RESULTS CAME FROM. See the class note.
        $candidates = ListingSearch::near($lat, $lng, $radiusKm, [
            'country'     => $country,
            'listing_ids' => array_keys($byListing),
            'limit'       => 200,
        ]);

        // Campaigns are keyed by listing id; results are identified by public
        // uuid, because near() deliberately does not select internal ids into
        // anything a template can reach. So translate once, here, rather than
        // widening the search's select list for advertising's convenience.
        $byUuid = [];

        foreach (DB::table('marketplace_listings')
                     ->whereIn('id', array_keys($byListing))
                     ->pluck('public_uuid', 'id') as $id => $uuid) {
            $byUuid[(string) $uuid] = $byListing[(int) $id];
        }

        if ($candidates === []) {
            return self::mark($organic);
        }

        $allowed = min(self::MAX_SLOTS, (int) floor(count($organic) * self::MAX_SHARE));

        if ($allowed < 1) {
            // A list of three has no room for a paid slot at a quarter share.
            return self::mark($organic);
        }

        $promoted   = [];
        $seenProvider = [];

        foreach ($candidates as $row) {
            if (count($promoted) >= $allowed) {
                break;
            }

            $provider = (int) ($row['provider_profile_id'] ?? 0);

            // Spec 15.3 keeps a diversity penalty so one provider cannot
            // dominate a page. Buying the slots would be the way around it.
            if (isset($seenProvider[$provider])) {
                continue;
            }

            $campaign = $byUuid[self::idOf($row)] ?? null;

            if ($campaign === null) {
                continue;
            }

            $seenProvider[$provider] = true;

            // ⛔ The row is passed through untouched and two keys are added.
            // distance_km, verification and everything else are exactly what
            // the organic query returned.
            $promoted[] = $row + [
                'sponsored'        => true,
                'disclosure_label' => $campaign->disclosure_label,
                'campaign_uuid'    => $campaign->public_uuid,
            ];
        }

        if ($promoted === []) {
            return self::mark($organic);
        }

        $paidIds = array_map(fn ($r) => self::idOf($r), $promoted);

        $rest = array_values(array_filter(
            $organic,
            fn ($r) => !in_array(self::idOf($r), $paidIds, true)
        ));

        if (!empty($opts['record'])) {
            self::recordImpressions($promoted, $byUuid);
        }

        return array_merge($promoted, self::mark($rest));
    }

    /** Record what was actually put in front of somebody. Spec 15.4. */
    public static function recordEvent(string $campaignUuid, string $type, ?int $listingId = null): void
    {
        if (!in_array($type, ['impression', 'click', 'contact'], true)) {
            return;
        }

        $campaignId = DB::table('sponsored_campaigns')->where('public_uuid', $campaignUuid)->value('id');

        if ($campaignId === null) {
            return;
        }

        DB::table('sponsored_events')->insert([
            'campaign_id'   => $campaignId,
            'listing_id'    => $listingId,
            'event_type'    => $type,
            'viewer_bucket' => self::bucket(),
            'occurred_at'   => now(),
        ]);
    }

    /**
     * What a campaign got, for the provider's own reporting.
     *
     * @return array{impression: int, click: int, contact: int}
     */
    public static function tally(int $campaignId): array
    {
        $rows = DB::table('sponsored_events')
            ->where('campaign_id', $campaignId)
            ->groupBy('event_type')
            ->selectRaw('event_type, count(*) as n')
            ->pluck('n', 'event_type');

        return [
            'impression' => (int) ($rows['impression'] ?? 0),
            'click'      => (int) ($rows['click'] ?? 0),
            'contact'    => (int) ($rows['contact'] ?? 0),
        ];
    }

    /* -------------------------------------------------------------- innards */

    /**
     * Is this reader inside the campaign's geography? Spec 15.4 requires the
     * country and category restrictions to be enforceable; a radius is the
     * third. A campaign with no radius reaches its whole country.
     */
    private static function reaches(object $c, float $lat, float $lng): bool
    {
        if ($c->radius_metres === null || $c->centre_lat === null) {
            return true;
        }

        $x = deg2rad((float) $c->centre_lat - $lat);
        $y = deg2rad((float) $c->centre_lng - $lng);
        $h = sin($x / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float) $c->centre_lat)) * sin($y / 2) ** 2;

        return 6371000 * 2 * asin(min(1.0, sqrt($h))) <= (float) $c->radius_metres;
    }

    /**
     * Every row carries the key, sponsored or not.
     *
     * A template asking `if (isset($row['sponsored']))` would label nothing on
     * the day the key went missing, and unlabelled is the one failure mode
     * spec 15.4 cares about. Present and false everywhere is safer than
     * present sometimes.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function mark(array $rows): array
    {
        return array_map(fn ($r) => $r + ['sponsored' => false, 'disclosure_label' => null], $rows);
    }

    private static function idOf(array $row): string
    {
        return (string) ($row['public_uuid'] ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $promoted
     * @param  array<int, object>  $byListing
     */
    private static function recordImpressions(array $promoted, array $byListing): void
    {
        foreach ($promoted as $row) {
            self::recordEvent((string) $row['campaign_uuid'], 'impression');
        }
    }

    /**
     * A coarse, daily-rotating bucket so one reader refreshing twenty times is
     * not sold as twenty impressions.
     *
     * ⛔ Deliberately not identifying. It mixes in the date, so it cannot be
     * followed across days, and there is no user id, IP or user agent stored
     * beside it. This is a billing sanity check, not an audience profile.
     */
    private static function bucket(): string
    {
        $seed = (string) (request()?->ip() ?? 'cli');

        return hash('sha256', $seed . '|' . now()->toDateString() . '|' . config('app.key'));
    }
}

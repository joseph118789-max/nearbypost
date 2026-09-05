<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "What can I buy, eat, hire, sell or share near this location?"
 *
 * ⛔ EVERY GEOGRAPHIC QUERY IN MARKETPLACE GOES THROUGH THIS CLASS. That is the
 * whole design, and it is why PostGIS can stay a later decision rather than a
 * rewrite. Today the radius is a bounding box plus haversine on numeric
 * columns; the day a geography column and a GiST index exist, boundedBy() and
 * distanceSql() change and nothing else does.
 *
 * Measured on this server, 4 Sep 2026, at 250,000 listings: the box narrows a
 * 10 km search to ~12,700 rows and the query costs 56 ms, saturating four cores
 * at roughly 100 searches a second. A 50 km search stops being selective and
 * reverts to a parallel sequential scan at 107 ms. Those are the numbers a
 * spatial index would change.
 *
 * ⛔ ONE PREDICATE FOR "LIVE", USED BY EVERY CALLER. Counts, lists, map pins and
 * the admin dashboard all call visible(). This project has already paid for the
 * alternative: the live screen's buckets and its drill-downs each built their
 * own conditions, disagreed, and neither looked wrong on screen.
 */
class ListingSearch
{
    /** Degrees of latitude per kilometre. The same everywhere on earth. */
    private const KM_PER_DEGREE_LAT = 111.32;

    /**
     * A listing the public may see.
     *
     * Both gates must agree (§13.4): the owner published it AND a moderator or
     * the AI accepted it. Either alone is not enough, and expiry overrides both.
     */
    public static function visible(): Builder
    {
        return DB::table('marketplace_listings as l')
            ->where('l.publication_status', 'published')
            ->whereIn('l.moderation_status', ['ai_accepted', 'manual_accepted'])
            ->where('l.expires_at', '>', now())
            ->whereNull('l.deleted_at');
    }

    /**
     * Listings near a point, nearest first.
     *
     * @param  array{category_id?: int, country?: string, kind?: string, provider_id?: int,
     *                listing_ids?: list<int>, limit?: int}  $filters
     * @return list<array<string, mixed>>
     */
    public static function near(float $lat, float $lng, float $radiusKm, array $filters = []): array
    {
        $country = strtoupper((string) ($filters['country'] ?? ''));

        // ⛔ The flag is checked HERE, not only in the controller. A search
        // service that returns rows for a country where Marketplace is off is
        // one forgotten guard away from leaking the whole module.
        if ($country !== '' && FeatureFlags::off('marketplace_enabled', $country)) {
            return [];
        }

        $limit    = max(1, min(200, (int) ($filters['limit'] ?? 40)));
        $radiusKm = max(0.1, min(200.0, $radiusKm));
        $distance = self::distanceSql($lat, $lng);

        $q = self::visible()
            ->whereNotNull('l.public_lat')
            ->whereNotNull('l.public_lng');

        self::boundedBy($q, $lat, $lng, $radiusKm);

        if ($country !== '') {
            $q->where('l.country_code', $country);
        }

        if (!empty($filters['category_id'])) {
            // A parent includes its children: choosing "Food & Dining" must find
            // a home-prepared food offer filed under the child category.
            $q->whereIn('l.category_id', self::withDescendants((int) $filters['category_id']));
        }

        if (!empty($filters['kind'])) {
            $q->where('l.listing_kind', $filters['kind']);
        }

        if (!empty($filters['provider_id'])) {
            $q->where('l.provider_profile_id', (int) $filters['provider_id']);
        }

        // A named set of listings, still through THIS predicate and THIS
        // distance. SponsoredPlacements uses it to fetch paid candidates, and
        // that is the whole reason it exists here rather than as a second
        // query over there: a sponsored result must carry a distance computed
        // the same way as an organic one, or spec 15.4's "preserve truthful
        // distance" is a promise made in a comment. Same visibility rules
        // too - paying does not publish an unmoderated or expired listing.
        if (!empty($filters['listing_ids'])) {
            $q->whereIn('l.id', array_map('intval', (array) $filters['listing_ids']));
        }

        // ⛔ The private columns are never in the select list. Not "not shown" -
        // not selected, so no template can reach one by accident (§13.2).
        $rows = $q->selectRaw(
            "l.public_uuid, l.slug, l.title, l.description, l.listing_kind,
             l.category_id, l.price_mode, l.price_min, l.price_max, l.currency,
             l.quantity_available, l.availability_ends_at, l.expires_at,
             l.public_location_mode, l.public_lat, l.public_lng,
             l.provider_profile_id, l.sponsorship_status, l.published_at,
             {$distance} AS distance_km"
        )
            // ⛔ WHERE, not HAVING. Postgres reads a HAVING with no GROUP BY as
            // one aggregate over the whole result, and then demands every
            // selected column be aggregated: "column l.public_uuid must appear
            // in the GROUP BY clause". The distance test is per row, so it is a
            // WHERE.
            ->whereRaw("{$distance} <= ?", [$radiusKm])
            ->orderByRaw("{$distance} ASC")
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => (array) $r)->all();
    }

    /**
     * How many are near here, by category, for the filter chips.
     *
     * The same predicate and the same box as near(), because a chip promising
     * eleven results beside a list showing four is the bug this shares code to
     * prevent.
     *
     * @return array<int, int> category id => count
     */
    public static function countByCategory(float $lat, float $lng, float $radiusKm, ?string $country = null): array
    {
        $radiusKm = max(0.1, min(200.0, $radiusKm));
        $distance = self::distanceSql($lat, $lng);

        $q = self::visible()
            ->whereNotNull('l.public_lat')
            ->whereNotNull('l.public_lng');

        self::boundedBy($q, $lat, $lng, $radiusKm);

        if ($country !== null && $country !== '') {
            $q->where('l.country_code', strtoupper($country));
        }

        // The distance test filters ROWS before they are grouped, so it is a
        // WHERE here too. As a HAVING it would ask whether the whole category
        // is within the radius, which is not a question anyone asked.
        return $q->whereRaw("{$distance} <= ?", [$radiusKm])
            ->selectRaw("l.category_id, count(*) AS n")
            ->groupBy('l.category_id')
            ->pluck('n', 'category_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * The box that lets an index do the first pass.
     *
     * ⛔ THIS IS THE SEAM. Everything a different database could do better lives
     * here and in distanceSql(). With PostGIS the body becomes ST_DWithin
     * against a geography column, a GiST index answers it, and no caller above
     * changes at all.
     *
     * The box is deliberately a little generous - a square around a circle -
     * because the exact haversine still decides. Too tight a box would quietly
     * clip results at the corners of the radius.
     */
    private static function boundedBy(Builder $q, float $lat, float $lng, float $radiusKm): void
    {
        $latDelta = $radiusKm / self::KM_PER_DEGREE_LAT;

        // Longitude degrees shrink towards the poles. Guarded because cos(90°)
        // is zero and a search at the pole would divide by it.
        $cos      = max(0.01, cos(deg2rad($lat)));
        $lngDelta = $radiusKm / (self::KM_PER_DEGREE_LAT * $cos);

        $q->whereBetween('l.public_lat', [$lat - $latDelta, $lat + $latDelta])
          ->whereBetween('l.public_lng', [$lng - $lngDelta, $lng + $lngDelta]);
    }

    /**
     * Great-circle distance in kilometres, with the reader's position already
     * written into the expression.
     *
     * ⛔ THE POSITION IS FORMATTED IN, NOT BOUND, AND THAT IS DELIBERATE.
     * Written with placeholders this expression needs three bindings in the
     * order lat, lng, lat - and it appears three times in one query, in the
     * SELECT, the HAVING and the ORDER BY, each needing its own copy in the
     * right order. The first draft of this class got that order wrong (lat,
     * lat, lng) and would have returned confidently wrong distances with no
     * error anywhere. Both arguments are floats the caller has already cast, so
     * there is nothing to inject; %.8F pins the format against a locale that
     * would otherwise write a decimal comma and produce invalid SQL.
     *
     * LEAST(1.0, ...) is not decoration: floating point can push the cosine a
     * hair above 1 for a point compared with itself, and acos(1.0000001) is
     * NaN, which sorts unpredictably and silently drops the row the reader is
     * standing on.
     */
    private static function distanceSql(float $lat, float $lng): string
    {
        return sprintf(
            '(6371.0 * acos(LEAST(1.0,
                cos(radians(%.8F)) * cos(radians(l.public_lat)) *
                cos(radians(l.public_lng) - radians(%.8F)) +
                sin(radians(%.8F)) * sin(radians(l.public_lat))
            )))',
            $lat, $lng, $lat
        );
    }

    /**
     * A category and everything under it.
     *
     * The tree is two levels deep by design (§4.4), so one query is enough and
     * a recursive CTE would be ceremony. If it ever grows a third level, this
     * is the method that has to know.
     *
     * @return list<int>
     */
    private static function withDescendants(int $categoryId): array
    {
        $children = DB::table('marketplace_categories')
            ->where('parent_id', $categoryId)
            ->pluck('id')
            ->all();

        return array_merge([$categoryId], array_map('intval', $children));
    }
}

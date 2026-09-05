<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Property listings, mirrored from ListingMine. Spec 27.
 *
 * NearbyPost provides discovery, distance ranking and traffic. ListingMine
 * remains the source of truth - and that is enforced by a database trigger, not
 * by this class behaving well. See the migration.
 *
 * ⛔ THIS CLASS IS THE ONLY THING IN THE CODEBASE ALLOWED TO DECLARE ITSELF A
 * SYNC. `sync()` sets `app.listingmine_sync` for the length of one transaction,
 * which is what lets the trigger tell an authorised update from any other. If a
 * second place ever needs that setting, that is the moment to stop and ask why
 * NearbyPost is writing another system's data.
 */
class PropertyMirror
{
    /** Spec 27, word for word. Shown on every property listing. */
    public const DISCLOSURE = 'Property listing powered by ListingMine';

    /**
     * Where a property link may point.
     *
     * ⛔ Spec 29: "Validate redirect/WhatsApp URLs to prevent open redirects."
     * The canonical URL arrives from another system and is then rendered as an
     * outbound link that NearbyPost counts clicks on - which is exactly the
     * shape of an open redirect. A payload naming any other host is refused at
     * the door rather than filtered at render time, because the render is in a
     * template and templates get copied.
     */
    private const ALLOWED_HOST = 'listingmine.com';

    /* ---------------------------------------------------------------- sync */

    /**
     * Take one property from ListingMine. Idempotent, and safe to replay.
     *
     * @param  array{id: string, url: string, title: string, deal_type: string,
     *               country: string, status: string, updated_at: string,
     *               agent_reference?: ?string, agent_name?: ?string,
     *               property_type?: ?string, price?: ?float, currency?: ?string,
     *               price_period?: ?string, region?: ?string, city?: ?string,
     *               lat?: ?float, lng?: ?float, image_url?: ?string,
     *               bedrooms?: ?int, bathrooms?: ?int, built_up_sqft?: ?int}  $p
     *
     * @return string  created | updated | unchanged | ignored_stale
     *
     * @throws PropertyRefused
     */
    public static function sync(array $p): string
    {
        foreach (['id', 'url', 'title', 'deal_type', 'country', 'status', 'updated_at'] as $need) {
            if (!isset($p[$need]) || trim((string) $p[$need]) === '') {
                throw new PropertyRefused('A property needs ' . $need . '.');
            }
        }

        if (!in_array($p['deal_type'], ['sale', 'rent'], true)) {
            throw new PropertyRefused('A property is for sale or for rent.');
        }

        self::assertListingMineUrl((string) $p['url']);

        if (!empty($p['image_url'])) {
            self::assertListingMineUrl((string) $p['image_url']);
        }

        $sourceUpdated = \Carbon\Carbon::parse($p['updated_at']);

        $existing = DB::table('property_listings')
            ->where('listingmine_property_id', (string) $p['id'])
            ->first(['id', 'source_updated_at']);

        // ⛔ A REPLAYED OR LATE PAYLOAD MUST NOT REGRESS NEWER DATA. Feeds are
        // retried and can arrive out of order; without this, yesterday's price
        // overwrites today's the moment a queue drains backwards. Their clock
        // decides, not ours and not arrival order.
        if ($existing !== null
            && \Carbon\Carbon::parse($existing->source_updated_at)->greaterThan($sourceUpdated)) {
            return 'ignored_stale';
        }

        $mirrored = [
            'listingmine_property_id' => (string) $p['id'],
            'canonical_url'    => (string) $p['url'],
            'agent_reference'  => $p['agent_reference'] ?? null,
            'agent_name'       => $p['agent_name'] ?? null,
            'title'            => mb_substr((string) $p['title'], 0, 300),
            'property_type'    => $p['property_type'] ?? null,
            'deal_type'        => (string) $p['deal_type'],
            'price'            => isset($p['price']) ? (float) $p['price'] : null,
            'currency'         => isset($p['currency']) ? strtoupper((string) $p['currency']) : null,
            'price_period'     => $p['price_period'] ?? null,
            'country_code'     => strtoupper((string) $p['country']),
            'region_code'      => $p['region'] ?? null,
            'city_name'        => $p['city'] ?? null,
            'public_lat'       => isset($p['lat']) ? (float) $p['lat'] : null,
            'public_lng'       => isset($p['lng']) ? (float) $p['lng'] : null,
            'main_image_url'   => $p['image_url'] ?? null,
            'bedrooms'         => isset($p['bedrooms']) ? (int) $p['bedrooms'] : null,
            'bathrooms'        => isset($p['bathrooms']) ? (int) $p['bathrooms'] : null,
            'built_up_sqft'    => isset($p['built_up_sqft']) ? (int) $p['built_up_sqft'] : null,
            'source_status'    => (string) $p['status'],
            'source_updated_at' => $sourceUpdated,
        ];

        return DB::transaction(function () use ($mirrored, $existing) {
            // The one place in the codebase that says this. See the class note.
            //
            // ⛔⛔ AND IT IS TURNED OFF AGAIN IN THE finally, WHICH IS NOT
            // TIDINESS - IT IS THE GUARD.
            //
            // SET LOCAL is scoped to the TRANSACTION, not to this closure. When
            // sync() is called inside an outer transaction - a batch importer,
            // a console command, the invariant suite - DB::transaction() is
            // only a SAVEPOINT, so without this reset the setting stays on for
            // every statement that follows in that outer transaction, and the
            // source-of-truth trigger is silently disabled for the rest of it.
            // Measured exactly that way: four "NearbyPost cannot overwrite
            // this" checks passed while the writes were in fact going through.
            //
            // The authorised window is now the statements between these two
            // lines and nothing else.
            DB::statement("SET LOCAL app.listingmine_sync = 'on'");

            try {
                if ($existing === null) {
                    DB::table('property_listings')->insert($mirrored + [
                        'public_uuid'      => (string) Str::uuid(),

                        // ⛔ Local columns are set on INSERT and never touched
                        // by a later sync. A moderator hid this for a reason,
                        // and a routine price update from ListingMine must not
                        // un-hide it.
                        'local_visibility' => 'visible',
                        'last_synced_at'   => now(),
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);

                    return 'created';
                }

                $before = (array) DB::table('property_listings')->where('id', $existing->id)
                    ->first(array_keys($mirrored));

                DB::table('property_listings')->where('id', $existing->id)
                    ->update($mirrored + ['last_synced_at' => now(), 'updated_at' => now()]);

                $after = (array) DB::table('property_listings')->where('id', $existing->id)
                    ->first(array_keys($mirrored));

                return $before == $after ? 'unchanged' : 'updated';
            } finally {
                DB::statement("SET LOCAL app.listingmine_sync = 'off'");
            }
        });
    }

    /* ------------------------------------------------------------- reading */

    /**
     * One predicate for "a reader may see this property".
     *
     * Spec 27: "Remove or mark unavailable when ListingMine status changes."
     * Availability is THEIR status, and the only thing NearbyPost contributes
     * is a local veto.
     */
    public static function visible(): Builder
    {
        return DB::table('property_listings')
            ->where('source_status', 'active')
            ->where('local_visibility', 'visible');
    }

    /**
     * Properties near a point. Discovery and distance ranking - our half of
     * the arrangement in spec 27.
     *
     * @return list<array<string, mixed>>
     */
    public static function near(float $lat, float $lng, float $radiusKm, string $country, int $limit = 40): array
    {
        $country = strtoupper($country);

        if (FeatureFlags::off('property_listingmine_enabled', $country)) {
            return [];
        }

        $radiusKm = max(0.1, min(200.0, $radiusKm));
        $limit    = max(1, min(200, $limit));

        $distance = sprintf(
            '(6371 * acos(least(1, cos(radians(%.8F)) * cos(radians(public_lat))
              * cos(radians(public_lng) - radians(%.8F))
              + sin(radians(%.8F)) * sin(radians(public_lat)))))',
            $lat, $lng, $lat
        );

        $rows = self::visible()
            ->where('country_code', $country)
            ->whereNotNull('public_lat')
            ->whereNotNull('public_lng')
            ->selectRaw(
                "public_uuid, title, property_type, deal_type, price, currency, price_period,
                 city_name, region_code, public_lat, public_lng, main_image_url,
                 bedrooms, bathrooms, built_up_sqft, source_updated_at,
                 {$distance} AS distance_km"
            )
            ->whereRaw("{$distance} <= ?", [$radiusKm])
            ->orderByRaw("{$distance} ASC")
            ->limit($limit)
            ->get();

        // ⛔ The disclosure travels WITH the row, so a template cannot render a
        // property without it by forgetting a partial. Spec 27 requires it on
        // display; attaching it here is what makes forgetting hard.
        return $rows->map(fn ($r) => (array) $r + ['powered_by' => self::DISCLOSURE])->all();
    }

    /* ------------------------------------------------------------ our side */

    /**
     * Hide a property on NearbyPost only. The single lever we have.
     *
     * ⛔ This does not tell ListingMine anything and does not change one field
     * of theirs. If a listing is genuinely wrong, it is wrong at the source and
     * somebody has to fix it there - hiding it here is a local decision about
     * our own site, and the reason is recorded so it can be reversed.
     *
     * @throws PropertyRefused
     */
    public static function hideLocally(string $publicUuid, string $reason): void
    {
        if (trim($reason) === '') {
            throw new PropertyRefused('Say why this property is being hidden.');
        }

        $n = DB::table('property_listings')->where('public_uuid', $publicUuid)
            ->update([
                'local_visibility' => 'hidden_locally',
                'hidden_reason'    => mb_substr(trim($reason), 0, 200),
                'updated_at'       => now(),
            ]);

        if ($n === 0) {
            throw new PropertyRefused('No such property.');
        }
    }

    public static function show(string $publicUuid): void
    {
        DB::table('property_listings')->where('public_uuid', $publicUuid)
            ->update(['local_visibility' => 'visible', 'hidden_reason' => null, 'updated_at' => now()]);
    }

    /* -------------------------------------------------------- outbound ---- */

    /**
     * Where "View on ListingMine" goes, checked again on the way out.
     *
     * The host was validated when the payload arrived. It is validated again
     * here because the two moments are years apart in practice: a row written
     * before a rule existed, or restored from a backup, must not become a
     * redirect to anywhere.
     *
     * @throws PropertyRefused
     */
    public static function outboundUrl(string $publicUuid): string
    {
        $url = DB::table('property_listings')->where('public_uuid', $publicUuid)->value('canonical_url');

        if ($url === null) {
            throw new PropertyRefused('No such property.');
        }

        self::assertListingMineUrl((string) $url);

        return (string) $url;
    }

    /** Spec 27: "Track outbound traffic to ListingMine." */
    public static function recordClick(string $publicUuid): void
    {
        $id = DB::table('property_listings')->where('public_uuid', $publicUuid)->value('id');

        if ($id === null) {
            return;
        }

        DB::table('property_click_events')->insert([
            'property_listing_id' => $id,
            'viewer_bucket'       => self::bucket(),
            'occurred_at'         => now(),
        ]);
    }

    public static function clicks(string $publicUuid): int
    {
        return (int) DB::table('property_click_events as e')
            ->join('property_listings as p', 'p.id', '=', 'e.property_listing_id')
            ->where('p.public_uuid', $publicUuid)
            ->count();
    }

    /* -------------------------------------------------------------- innards */

    /** @throws PropertyRefused */
    private static function assertListingMineUrl(string $url): void
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https') {
            throw new PropertyRefused('A property link must be https.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        // ⛔ Exact host, or a subdomain of it. A plain `str_contains` would
        // accept `listingmine.com.attacker.net` and `evil-listingmine.com`,
        // which is how open redirects are usually built.
        $ok = $host === self::ALLOWED_HOST
            || str_ends_with($host, '.' . self::ALLOWED_HOST);

        if (!$ok) {
            throw new PropertyRefused('A property link must point at ListingMine, not ' . ($host ?: 'nowhere') . '.');
        }
    }

    /** Coarse and daily-rotating; a traffic count, never an audience profile. */
    private static function bucket(): string
    {
        $seed = (string) (request()?->ip() ?? 'cli');

        return hash('sha256', $seed . '|' . now()->toDateString() . '|' . config('app.key'));
    }
}

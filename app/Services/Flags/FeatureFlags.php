<?php

namespace App\Services\Flags;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Is this capability on, here?
 *
 *   FeatureFlags::on('carpool_enabled')            // the site's home country
 *   FeatureFlags::on('carpool_enabled', 'SG')      // Singapore
 *
 * Spec §32. Two rules decide every answer, and both matter:
 *
 *   1. A country's own row wins over the global default.
 *   2. ⛔ ABSENCE MEANS OFF - at both levels. A flag with no global row is off.
 *      A SENSITIVE flag with a global row but no row for the country asked
 *      about is still off for that country.
 *
 * The second rule is the one worth being careful about. Spec §32: "Sensitive
 * capability activation must default off in unconfigured countries." If an
 * unconfigured country inherited the global answer, adding Indonesia to
 * SITE_COUNTRIES would switch Car Pool on there before anyone had read
 * Indonesian transport law. So for sensitive flags a country must be named
 * explicitly; for ordinary ones the global row is a sane default.
 */
class FeatureFlags
{
    /**
     * Flags a country must opt into by name. Each either carries legal exposure
     * - transport law, professional advertising rules - or takes money, and
     * none of it should arrive in a new country by inheritance.
     */
    private const REQUIRES_EXPLICIT_COUNTRY = [
        'carpool_enabled',
        'professional_services_enabled',
        'sponsored_listings_enabled',
    ];

    private const TTL = 300;

    /**
     * @param  ?string  $country  ISO-3166 alpha-2. Null means the site's home
     *                            country, the first of SITE_COUNTRIES.
     */
    public static function on(string $key, ?string $country = null): bool
    {
        $country = $country === null ? self::homeCountry() : strtoupper($country);
        $flat    = self::flat();

        // The country's own answer, if it has one, decides. Nothing else does.
        if (array_key_exists($country . '|' . $key, $flat)) {
            return $flat[$country . '|' . $key];
        }

        if (in_array($key, self::REQUIRES_EXPLICIT_COUNTRY, true)) {
            return false;   // no row for this country, and this flag needs one
        }

        return $flat['|' . $key] ?? false;
    }

    /** The opposite, for a call site that guards rather than allows. */
    public static function off(string $key, ?string $country = null): bool
    {
        return !self::on($key, $country);
    }

    /**
     * Every flag's state for one country, for an admin screen.
     *
     * @return array<string, array{enabled: bool, source: string, note: ?string}>
     */
    public static function forCountry(string $country): array
    {
        $country = strtoupper($country);
        $out     = [];

        foreach (self::rows() as $row) {
            if ($row['country_code'] !== null && strtoupper($row['country_code']) !== $country) {
                continue;
            }

            $isCountryRow = $row['country_code'] !== null;

            // A country row always beats the global one; the global one is only
            // recorded when nothing has claimed the key yet.
            if ($isCountryRow || !isset($out[$row['key']])) {
                $out[$row['key']] = [
                    'enabled' => $row['enabled'],
                    'source'  => $isCountryRow ? $country : 'default',
                    'note'    => $row['note'],
                ];
            }
        }

        // A sensitive flag with no row for this country reads as off, and the
        // screen should say WHY rather than showing the global answer.
        foreach (self::REQUIRES_EXPLICIT_COUNTRY as $key) {
            if (isset($out[$key]) && $out[$key]['source'] === 'default') {
                $out[$key] = [
                    'enabled' => false,
                    'source'  => 'off until ' . $country . ' is configured',
                    'note'    => $out[$key]['note'],
                ];
            }
        }

        return $out;
    }

    /** Turn one on or off for one country, or globally when country is null. */
    public static function set(string $key, ?string $country, bool $enabled, ?string $note = null, ?int $adminId = null): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => $key, 'country_code' => $country === null ? null : strtoupper($country)],
            [
                'enabled'             => $enabled,
                'note'                => $note,
                'changed_by_admin_id' => $adminId,
                'updated_at'          => now(),
                'created_at'          => now(),
            ]
        );

        self::forget();
    }

    public static function forget(): void
    {
        Cache::forget('feature_flags:rows');
    }

    /** @return array<string, bool> keyed "COUNTRY|key", with "" for the global row */
    private static function flat(): array
    {
        $flat = [];

        foreach (self::rows() as $row) {
            $flat[strtoupper((string) $row['country_code']) . '|' . $row['key']] = $row['enabled'];
        }

        return $flat;
    }

    /**
     * @return list<array{key: string, country_code: ?string, enabled: bool, note: ?string}>
     *
     * ⛔ PLAIN ARRAYS, NOT stdClass. The cache serialises whatever it is given,
     * and a stdClass came back as __PHP_Incomplete_Class on the first read
     * after the value was cached: "the script tried to access a property on an
     * incomplete object". Arrays survive every cache driver.
     */
    private static function rows(): array
    {
        return Cache::remember('feature_flags:rows', self::TTL, function () {
            // ⛔ Never let a missing table read as "everything on". A checkout
            // without the migration must behave like a site with all flags off.
            try {
                return DB::table('feature_flags')
                    ->get(['key', 'country_code', 'enabled', 'note'])
                    ->map(fn ($r) => [
                        'key'          => (string) $r->key,
                        'country_code' => $r->country_code === null ? null : (string) $r->country_code,
                        'enabled'      => (bool) $r->enabled,
                        'note'         => $r->note === null ? null : (string) $r->note,
                    ])
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /** The first country in SITE_COUNTRIES: the one the site is primarily for. */
    private static function homeCountry(): string
    {
        $list = \App\Services\Geo\SourceCountry::siteCountries();

        return $list[0] ?? 'MY';
    }
}

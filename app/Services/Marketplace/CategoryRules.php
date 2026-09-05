<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a category means in one country. Spec §4.3, §20.6.
 *
 * ⛔ THIS CLASS EXISTS SO THAT NO CONTROLLER EVER SAYS `if ($category ===
 * 'car_pool')`. Spec §4.3: "Do not implement category behaviour with large
 * controller if/else blocks." Every difference - who may post, what must be
 * verified, how long a listing lives, whether a price may be shown, what
 * claims are forbidden - is a row, and this is the only thing that reads it.
 *
 * ⛔ NO ROW MEANS THE CATEGORY IS CLOSED IN THAT COUNTRY. Same rule as the
 * feature flags: a category opens for business when somebody enables it, not
 * when a migration seeds the tree.
 */
class CategoryRules
{
    private const TTL = 300;

    /** Applied when a country's row leaves a field unset. Conservative on purpose. */
    private const DEFAULTS = [
        'allowed_provider_kinds'     => ['neighbour_provider', 'registered_business'],
        'minimum_verification_level' => 'phone',
        'default_expiry_days'        => 30,
        'requires_manual_review'     => false,
        'listing_limits'             => ['active_listings' => 3, 'images' => 5],
        'pricing_policy'             => ['mode' => 'optional'],
        'location_policy'            => ['allowed_modes' => ['approximate_area', 'service_area', 'online_only']],
    ];

    /**
     * @return ?array<string, mixed>  null when the category is not open here
     */
    public static function for(int $categoryId, string $country): ?array
    {
        $country = strtoupper($country);

        // ⛔ CACHE AN ARRAY, NEVER A stdClass. The cache serialises whatever it
        // is handed, and a stdClass comes back as __PHP_Incomplete_Class on the
        // first read after it was stored - "the script tried to access a
        // property on an incomplete object". FeatureFlags was bitten by this
        // the same day and fixed; this class then repeated it, which is what
        // happens when the lesson lives in one file instead of being a rule.
        $row = Cache::remember(
            "mp:rules:{$categoryId}:{$country}",
            self::TTL,
            function () use ($categoryId, $country) {
                $found = DB::table('marketplace_category_country_rules')
                    ->where('category_id', $categoryId)
                    ->where('country_code', $country)
                    ->first();

                return $found === null ? null : (array) $found;
            }
        );

        if ($row === null || empty($row['enabled'])) {
            return null;
        }

        $rules = ['category_id' => $categoryId, 'country_code' => $country];

        foreach (self::DEFAULTS as $key => $default) {
            $stored = $row[$key] ?? null;

            if (is_string($stored)) {
                $stored = json_decode($stored, true);
            }

            $rules[$key] = $stored ?? $default;
        }

        $rules['default_expiry_days']        = (int) ($row['default_expiry_days'] ?: self::DEFAULTS['default_expiry_days']);
        $rules['requires_manual_review']     = (bool) $row['requires_manual_review'];
        $rules['minimum_verification_level'] = (string) ($row['minimum_verification_level'] ?: self::DEFAULTS['minimum_verification_level']);
        $rules['field_schema']               = self::decode($row['field_schema'] ?? null);
        $rules['prohibited_claims']          = self::decode($row['prohibited_claims'] ?? null) ?? [];
        $rules['moderation_policy']          = self::decode($row['moderation_policy'] ?? null) ?? [];

        return $rules;
    }

    /** May a provider of this kind advertise in this category, here? */
    public static function allowsProviderKind(array $rules, string $providerKind): bool
    {
        return in_array($providerKind, (array) $rules['allowed_provider_kinds'], true);
    }

    /** May a listing here be shown at this precision? */
    public static function allowsLocationMode(array $rules, string $mode): bool
    {
        $allowed = $rules['location_policy']['allowed_modes'] ?? self::DEFAULTS['location_policy']['allowed_modes'];

        return in_array($mode, (array) $allowed, true);
    }

    /**
     * required | optional | prohibited
     *
     * Spec §11.6: "Country configuration may hide or restrict price
     * advertising" - some professional bodies forbid it outright.
     */
    public static function pricing(array $rules): string
    {
        return (string) ($rules['pricing_policy']['mode'] ?? 'optional');
    }

    public static function maxActiveListings(array $rules): int
    {
        return (int) ($rules['listing_limits']['active_listings'] ?? self::DEFAULTS['listing_limits']['active_listings']);
    }

    public static function maxImages(array $rules): int
    {
        return (int) ($rules['listing_limits']['images'] ?? self::DEFAULTS['listing_limits']['images']);
    }

    public static function forget(): void
    {
        Cache::flush();
    }

    /** @return ?array<mixed> */
    private static function decode(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}

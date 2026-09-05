<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and submits a listing, refusing anything the country's rules for that
 * category do not allow.
 *
 * ⛔ EVERY REFUSAL HERE IS A SERVER DECISION. Spec §0.8: "Treat all IDs,
 * verification states and ownership relationships as server-authoritative.
 * Never trust client-submitted ownership, ratings, verification badges or
 * moderation status." So the caller passes what the person typed, and this
 * class decides the provider, the expiry, the public position and both status
 * columns. None of those may be supplied.
 */
class ListingWriter
{
    /**
     * @param  array<string, mixed>  $input  what the person typed
     * @return int  the new listing id, always in draft
     *
     * @throws ListingRefused
     */
    public static function draft(int $userId, int $providerId, int $categoryId, array $input): int
    {
        $provider = DB::table('provider_profiles')->where('id', $providerId)->whereNull('deleted_at')
            ->first(['id', 'provider_kind', 'country_code', 'publication_status']);

        if ($provider === null) {
            throw new ListingRefused('That provider profile no longer exists.');
        }

        // ⛔ The person must actually be allowed to act for this provider, and
        // membership is read from the database, never from the request.
        $role = DB::table('provider_members')
            ->where('provider_profile_id', $providerId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('role');

        if (!in_array((string) $role, ['owner', 'manager', 'content_editor'], true)) {
            throw new ListingRefused('You do not have permission to post for this provider.');
        }

        $country = strtoupper((string) $provider->country_code);

        if (FeatureFlags::off('marketplace_enabled', $country)) {
            throw new ListingRefused('Marketplace is not open in this country yet.');
        }

        $rules = CategoryRules::for($categoryId, $country);

        if ($rules === null) {
            throw new ListingRefused('That category is not open in this country yet.');
        }

        if (!CategoryRules::allowsProviderKind($rules, (string) $provider->provider_kind)) {
            throw new ListingRefused('This kind of provider cannot advertise in that category.');
        }

        $mode = (string) ($input['public_location_mode'] ?? 'approximate_area');

        if (!CategoryRules::allowsLocationMode($rules, $mode)) {
            throw new ListingRefused('That location precision is not allowed in this category.');
        }

        self::checkPricing($rules, $input);
        self::checkActiveLimit($rules, $providerId, $categoryId);

        // ⛔ THE PUBLIC POSITION IS DERIVED HERE AND NOWHERE ELSE. The private
        // point is what the person gave us; the public one is what the server
        // decides may be shown. A caller cannot supply public_lat.
        $privateLat = self::float($input['lat'] ?? null);
        $privateLng = self::float($input['lng'] ?? null);
        $public     = PublicLocation::derive($mode, $privateLat, $privateLng);

        $title = trim((string) ($input['title'] ?? ''));

        if (mb_strlen($title) < 4) {
            throw new ListingRefused('Give the listing a title of at least four characters.');
        }

        return (int) DB::table('marketplace_listings')->insertGetId([
            'public_uuid'          => (string) Str::uuid(),
            'owner_user_id'        => $userId,
            'provider_profile_id'  => $providerId,
            'category_id'          => $categoryId,
            'listing_kind'         => (string) ($input['listing_kind'] ?? 'provider_service'),
            'title'                => mb_substr($title, 0, 200),
            'description'          => isset($input['description']) ? mb_substr((string) $input['description'], 0, 5000) : null,
            'structured_attributes' => isset($input['attributes']) ? json_encode($input['attributes']) : null,
            'country_code'         => $country,
            'region_code'          => $input['region_code'] ?? null,
            'city_name'            => $input['city_name'] ?? null,
            'public_location_mode' => $mode,
            'public_lat'           => $public['lat'],
            'public_lng'           => $public['lng'],
            'private_lat'          => $privateLat,
            'private_lng'          => $privateLng,
            'price_mode'           => (string) ($input['price_mode'] ?? 'quotation'),
            'price_min'            => self::float($input['price_min'] ?? null),
            'price_max'            => self::float($input['price_max'] ?? null),
            'currency'             => $input['currency'] ?? null,
            'quantity_available'   => isset($input['quantity']) ? max(0, (int) $input['quantity']) : null,
            'availability_starts_at' => $input['available_from'] ?? null,
            'availability_ends_at' => $input['available_until'] ?? null,

            // ⛔ Set from the country's rules, never from the request. A person
            // choosing their own expiry would choose "never".
            'expires_at'           => now()->addDays($rules['default_expiry_days']),

            // Always draft. Publishing is submit(), and it goes through
            // moderation - there is no path from a form straight to live.
            'publication_status'   => 'draft',
            'moderation_status'    => 'not_checked',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    /**
     * Hand a draft to moderation.
     *
     * ⛔ This does NOT publish. It moves the listing into the queue, and
     * publication happens when moderation accepts it. Spec §13.4 keeps the two
     * state machines apart precisely so this method cannot shortcut one.
     */
    public static function submit(int $listingId, int $userId): void
    {
        $listing = DB::table('marketplace_listings')->where('id', $listingId)->whereNull('deleted_at')
            ->first(['id', 'owner_user_id', 'provider_profile_id', 'category_id', 'country_code',
                     'publication_status', 'public_location_mode', 'public_lat', 'title']);

        if ($listing === null) {
            throw new ListingRefused('That listing no longer exists.');
        }

        if (!in_array($listing->publication_status, ['draft', 'rejected'], true)) {
            throw new ListingRefused('Only a draft can be submitted.');
        }

        // A listing that claims a place must have one before it goes anywhere -
        // the database refuses it at publish time, and finding out here is
        // kinder than finding out after a moderator has read it.
        if (in_array($listing->public_location_mode, ['exact_premises', 'approximate_area'], true)
            && $listing->public_lat === null) {
            throw new ListingRefused('Add a location before submitting this listing.');
        }

        $rules  = CategoryRules::for((int) $listing->category_id, (string) $listing->country_code);
        $manual = $rules !== null && $rules['requires_manual_review'];

        DB::table('marketplace_listings')->where('id', $listingId)->update([
            'publication_status' => 'submitted',
            'moderation_status'  => $manual ? 'manual_review' : 'ai_checking',
            'updated_at'         => now(),
        ]);

        DB::table('audit_events')->insert([
            'event'         => 'listing.submitted',
            'subject_type'  => 'listing',
            'subject_id'    => $listingId,
            'actor_user_id' => $userId,
            'after'         => json_encode(['moderation_status' => $manual ? 'manual_review' : 'ai_checking']),
            'created_at'    => now(),
        ]);
    }

    /** Spec §11.6: some categories forbid advertising a price at all. */
    private static function checkPricing(array $rules, array $input): void
    {
        $policy = CategoryRules::pricing($rules);
        $mode   = (string) ($input['price_mode'] ?? 'quotation');
        $given  = isset($input['price_min']) || isset($input['price_max']);

        if ($policy === 'prohibited' && ($given || $mode !== 'quotation')) {
            throw new ListingRefused('Prices cannot be advertised in this category here.');
        }

        if ($policy === 'required' && !$given && $mode !== 'free') {
            throw new ListingRefused('This category needs a price.');
        }
    }

    /** Spec §8.3: a neighbour provider gets three active offers, by default. */
    private static function checkActiveLimit(array $rules, int $providerId, int $categoryId): void
    {
        $max = CategoryRules::maxActiveListings($rules);

        $active = DB::table('marketplace_listings')
            ->where('provider_profile_id', $providerId)
            ->where('category_id', $categoryId)
            ->whereIn('publication_status', ['draft', 'submitted', 'published', 'paused'])
            ->whereNull('deleted_at')
            ->count();

        if ($active >= $max) {
            throw new ListingRefused(sprintf(
                'You already have %d listings in this category, which is the limit here. Remove one first.',
                $max
            ));
        }
    }

    private static function float(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}

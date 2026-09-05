<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setting up as a provider, once. Spec §8.
 *
 * ⛔ THE NEIGHBOUR PATH IS THE ONE TO GET RIGHT. Spec §8.3 exists so a retiree
 * can sell nasi lemak without being presented as a registered company, and
 * §34.1 makes it an acceptance test: "SSM is not required as the technical
 * entry gate, but registration is not represented as confirmed." Every field
 * asked for here is one she can answer in a minute on a phone; anything more
 * and the module has failed its first user.
 */
class ProviderOnboarding
{
    /**
     * Set up a Neighbour Provider. Spec §8.3.
     *
     * @param  array{name: string, area?: ?string, whatsapp_cc?: ?string, whatsapp?: ?string,
     *               category_id?: ?int, lat?: ?float, lng?: ?float, media_id?: ?int,
     *               accepted_terms?: bool, declared_accurate?: bool}  $input
     *
     * @throws ProviderRefused
     */
    public static function neighbour(int $userId, string $country, array $input): int
    {
        $country = strtoupper($country);

        if (FeatureFlags::off('neighbour_offers_enabled', $country)) {
            throw new ProviderRefused('Neighbour offers are not open here yet.');
        }

        $name = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($name) < 3) {
            throw new ProviderRefused('Give your offer a name people will recognise, like "Alex Weekend Nasi Lemak".');
        }

        // ⛔ Both declarations are required and neither is a formality: §8.3
        // lists safety and terms acceptance among the minimum fields, and a
        // provider who has not accepted the terms cannot be held to them.
        if (empty($input['accepted_terms']) || empty($input['declared_accurate'])) {
            throw new ProviderRefused('Accept the provider terms and confirm your details are accurate.');
        }

        // ⛔ ONE NEIGHBOUR IDENTITY PER PERSON, PER CATEGORY FAMILY. Spec §8.3's
        // recommended default. Somebody who sells food AND offers cleaning adds
        // the second category to the profile they have; they do not become two
        // neighbours, because two profiles would carry two separate ratings for
        // the same person and readers would see neither in full.
        $existing = DB::table('provider_members as m')
            ->join('provider_profiles as p', 'p.id', '=', 'm.provider_profile_id')
            ->where('m.user_id', $userId)->where('m.status', 'active')
            ->where('p.provider_kind', 'neighbour_provider')
            ->whereNull('p.deleted_at')
            ->value('p.id');

        if ($existing !== null) {
            throw new ProviderRefused('You already have a neighbour profile. Add another category to it instead of starting again.');
        }

        return DB::transaction(function () use ($userId, $country, $name, $input) {
            $public = PublicLocation::derive(
                'approximate_area',
                isset($input['lat']) ? (float) $input['lat'] : null,
                isset($input['lng']) ? (float) $input['lng'] : null
            );

            $providerId = (int) DB::table('provider_profiles')->insertGetId([
                'public_uuid'          => (string) Str::uuid(),
                'slug'                 => self::slug($name),
                'provider_kind'        => 'neighbour_provider',

                // ⛔ Home-based by default, and therefore never mapped exactly.
                // Spec §13.2: "Home addresses must not automatically be made
                // precise and public."
                'operating_mode'       => 'home_based',
                'public_location_mode' => 'approximate_area',

                'public_name'          => mb_substr($name, 0, 160),
                'primary_media_id'     => $input['media_id'] ?? null,
                'description'          => isset($input['description']) ? mb_substr((string) $input['description'], 0, 2000) : null,
                'country_code'         => $country,
                'city_name'            => isset($input['area']) ? mb_substr((string) $input['area'], 0, 120) : null,
                'public_lat'           => $public['lat'],
                'public_lng'           => $public['lng'],
                'private_lat'          => isset($input['lat']) ? (float) $input['lat'] : null,
                'private_lng'          => isset($input['lng']) ? (float) $input['lng'] : null,
                'whatsapp_country_code' => $input['whatsapp_cc'] ?? null,
                'whatsapp_number'      => $input['whatsapp'] ?? null,

                // ⛔ NOT "unverified". Spec §8.3 forbids that word as the
                // primary label; this records the fact, which is that no
                // registration was offered, and the panel says so in words.
                'registration_status'  => 'not_provided',
                'verification_summary_status' => 'none',

                'publication_status'   => 'published',
                'created_by_user_id'   => $userId,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            DB::table('provider_members')->insert([
                'provider_profile_id' => $providerId,
                'user_id'             => $userId,
                'role'                => 'owner',
                'status'              => 'active',
                'accepted_at'         => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            if (!empty($input['category_id'])) {
                DB::table('provider_categories')->insert([
                    'provider_profile_id' => $providerId,
                    'category_id'         => (int) $input['category_id'],
                    'status'              => 'approved',
                    'approved_at'         => now(),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            self::recordWhatIsKnown($providerId, $userId);

            DB::table('audit_events')->insert([
                'event'         => 'provider.created',
                'subject_type'  => 'provider',
                'subject_id'    => $providerId,
                'actor_user_id' => $userId,
                'after'         => json_encode(['kind' => 'neighbour_provider', 'country' => $country]),
                'created_at'    => now(),
            ]);

            return $providerId;
        });
    }

    /**
     * Set up a registered or established business. Spec §8.5.
     *
     * ⛔ A REGISTRATION NUMBER IS A CLAIM, NOT A CONFIRMATION. Somebody typing
     * one in has told us something; nobody has checked it. So the number is
     * stored, the status is `submitted`, and the public panel says "Business
     * registration submitted, not yet checked" until a moderator confirms it
     * against a register. Spec §8.5: "Confirmation is not an endorsement of
     * service quality" - and it is not a confirmation at all until somebody
     * does the confirming.
     *
     * @param  array{name: string, legal_name?: ?string, registration_number?: ?string,
     *               registration_authority?: ?string, operating_mode?: string,
     *               address?: ?string, lat?: ?float, lng?: ?float,
     *               whatsapp_cc?: ?string, whatsapp?: ?string, media_id?: ?int,
     *               accepted_terms?: bool, declared_accurate?: bool}  $input
     *
     * @throws ProviderRefused
     */
    public static function business(int $userId, string $country, array $input): int
    {
        $country = strtoupper($country);

        if (FeatureFlags::off('business_directory_enabled', $country)) {
            throw new ProviderRefused('Business listings are not open here yet.');
        }

        $name = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($name) < 3) {
            throw new ProviderRefused('Give the business its trading name.');
        }

        if (empty($input['accepted_terms']) || empty($input['declared_accurate'])) {
            throw new ProviderRefused('Accept the provider terms and confirm the details are accurate.');
        }

        $mode = (string) ($input['operating_mode'] ?? 'premises');

        if (!in_array($mode, ['premises', 'home_based', 'mobile_service_area', 'online_only'], true)) {
            throw new ProviderRefused('Choose where the business operates from.');
        }

        $number = trim((string) ($input['registration_number'] ?? ''));

        return DB::transaction(function () use ($userId, $country, $name, $input, $mode, $number) {
            // ⛔ Premises are public by design - a shop wants to be found - but
            // a home-based business is still a home, and the mode decides,
            // never the provider kind.
            $publicMode = $mode === 'premises' ? 'exact_premises' : 'approximate_area';

            $public = PublicLocation::derive(
                $publicMode,
                isset($input['lat']) ? (float) $input['lat'] : null,
                isset($input['lng']) ? (float) $input['lng'] : null
            );

            $providerId = (int) DB::table('provider_profiles')->insertGetId([
                'public_uuid'           => (string) Str::uuid(),
                'slug'                  => self::slug($name),
                'provider_kind'         => 'registered_business',
                'operating_mode'        => $mode,
                'public_location_mode'  => $publicMode,
                'public_name'           => mb_substr($name, 0, 160),
                'legal_name'            => isset($input['legal_name']) ? mb_substr((string) $input['legal_name'], 0, 200) : null,
                'primary_media_id'      => $input['media_id'] ?? null,
                'description'           => isset($input['description']) ? mb_substr((string) $input['description'], 0, 2000) : null,
                'country_code'          => $country,
                'public_lat'            => $public['lat'],
                'public_lng'            => $public['lng'],
                'private_lat'           => isset($input['lat']) ? (float) $input['lat'] : null,
                'private_lng'           => isset($input['lng']) ? (float) $input['lng'] : null,
                'private_address'       => $input['address'] ?? null,
                'whatsapp_country_code' => $input['whatsapp_cc'] ?? null,
                'whatsapp_number'       => $input['whatsapp'] ?? null,
                'registration_number'   => $number === '' ? null : mb_substr($number, 0, 80),
                'registration_authority' => isset($input['registration_authority'])
                    ? mb_substr((string) $input['registration_authority'], 0, 160) : null,

                // Claimed, not confirmed. The difference is the whole of §8.5.
                'registration_status'   => $number === '' ? 'not_provided' : 'submitted',
                'verification_summary_status' => 'none',

                // ⛔ A business waits for a person. Unlike a neighbour offer,
                // it advertises a registration, and a registration nobody has
                // looked at should not be on the site claiming to be one.
                'publication_status'    => 'draft',

                'created_by_user_id'    => $userId,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            DB::table('provider_members')->insert([
                'provider_profile_id' => $providerId,
                'user_id'             => $userId,
                'role'                => 'owner',
                'status'              => 'active',
                'accepted_at'         => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            self::recordWhatIsKnown($providerId, $userId);

            if ($number !== '') {
                DB::table('verification_checks')
                    ->where('subject_type', 'provider')->where('subject_id', $providerId)
                    ->where('verification_type', 'business_registration')
                    ->update(['status' => 'submitted', 'updated_at' => now()]);
            }

            DB::table('audit_events')->insert([
                'event'         => 'provider.created',
                'subject_type'  => 'provider',
                'subject_id'    => $providerId,
                'actor_user_id' => $userId,
                'after'         => json_encode(['kind' => 'registered_business', 'country' => $country,
                                                'registration' => $number === '' ? 'none given' : 'submitted']),
                'created_at'    => now(),
            ]);

            return $providerId;
        });
    }

    /**
     * Write down what is and is not confirmed, at the moment of creation.
     *
     * ⛔ THE ROWS THAT SAY "NOT CONFIRMED" ARE WRITTEN TOO. A panel built only
     * from positive rows would show a provider with a confirmed phone and
     * nothing else, and a reader would fill the silence themselves - usually
     * generously. Saying "Business registration not confirmed" out loud is the
     * whole point of §8.3.
     */
    private static function recordWhatIsKnown(int $providerId, int $userId): void
    {
        $user = DB::table('users')->where('id', $userId)
            ->first(['phone_verified_at', 'identity_verification_status']);

        $facts = [
            'phone' => $user && $user->phone_verified_at !== null
                ? ['status' => 'verified_document', 'method' => 'manual', 'checked_at' => $user->phone_verified_at]
                : ['status' => 'not_submitted', 'method' => null, 'checked_at' => null],

            'identity' => $user && $user->identity_verification_status === 'confirmed'
                ? ['status' => 'verified_document', 'method' => 'manual', 'checked_at' => now()]
                : ['status' => 'not_submitted', 'method' => null, 'checked_at' => null],

            // Nobody asked her for one, and nobody is pretending otherwise.
            'business_registration' => ['status' => 'not_submitted', 'method' => null, 'checked_at' => null],
        ];

        foreach ($facts as $type => $fact) {
            DB::table('verification_checks')->insert([
                'subject_type'      => 'provider',
                'subject_id'        => $providerId,
                'verification_type' => $type,
                'status'            => $fact['status'],
                'method'            => $fact['method'],
                'checked_at'        => $fact['checked_at'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    /** Readable, unique, and never derived from an id. */
    private static function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'provider';
        $slug = mb_substr($base, 0, 140);
        $n    = 1;

        while (DB::table('provider_profiles')->where('slug', $slug)->exists()) {
            $slug = mb_substr($base, 0, 134) . '-' . (++$n);
        }

        return $slug;
    }
}

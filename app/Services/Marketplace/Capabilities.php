<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\DB;

/**
 * May this person do this, here? Spec §7.
 *
 * The whole of §7's table lives in this one class, so a controller asks a
 * question rather than assembling an answer. Every call returns either null -
 * meaning yes - or a sentence the person can act on.
 *
 *   $why = Capabilities::denies($userId, 'neighbour_offer', 'MY');
 *   if ($why !== null) { return back()->withErrors(['do' => $why]); }
 *
 * ⛔ IT RETURNS THE REASON, NOT A BOOLEAN. A gate that answers false makes
 * every caller invent its own explanation, and the explanations drift until two
 * screens give different accounts of the same refusal. The reason is written
 * once, here, next to the rule that produced it.
 *
 * ⛔ CATEGORIES ARE NOT SEPARATE REGISTRATIONS. Spec §7: "Profiles and
 * high-risk capabilities are activated once." Somebody who has set up as a
 * Neighbour Provider to sell nasi lemak does not set up again to offer
 * cleaning - they add a category to the provider they already have.
 */
class Capabilities
{
    /**
     * @return ?string  null when allowed; otherwise why not, in plain words
     */
    public static function denies(int $userId, string $action, ?string $country = null): ?string
    {
        $user = DB::table('users')->where('id', $userId)
            ->first(['id', 'phone_verified_at', 'identity_verification_status', 'account_status']);

        if ($user === null) {
            return 'You need an account to do that.';
        }

        if ($user->account_status === 'suspended') {
            return 'This account is suspended.';
        }

        return match ($action) {
            // Anyone with an account. Already true today.
            'community_post', 'comment' => null,

            'review'            => self::forReview($user),
            'personal_item'     => self::forPersonalItem($user, $country),
            'neighbour_offer'   => self::forNeighbourOffer($user, $country),
            'business_listing'  => self::forBusinessListing($user, $country),
            'carpool_notice'    => self::forCarpool($user, $country),
            'professional_listing' => self::forProfessional($user, $country),

            default => 'That is not something this site does.',
        };
    }

    /** Convenience for a template that only needs yes or no. */
    public static function allows(int $userId, string $action, ?string $country = null): bool
    {
        return self::denies($userId, $action, $country) === null;
    }

    /**
     * Which provider profiles this person may post through, and as what.
     *
     * @return list<array{id: int, name: string, kind: string, role: string}>
     */
    public static function providersFor(int $userId): array
    {
        return DB::table('provider_members as m')
            ->join('provider_profiles as p', 'p.id', '=', 'm.provider_profile_id')
            ->where('m.user_id', $userId)
            ->where('m.status', 'active')
            ->whereIn('m.role', ['owner', 'manager', 'content_editor'])
            ->whereNull('p.deleted_at')
            ->get(['p.id', 'p.public_name as name', 'p.provider_kind as kind', 'm.role'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /* ------------------------------------------------------------ the rules */

    /** Spec §7: an account, plus anti-abuse eligibility. */
    private static function forReview(object $user): ?string
    {
        if ($user->account_status !== 'active') {
            return 'This account cannot leave reviews at the moment.';
        }

        return null;
    }

    /** Spec §7: an account and a confirmed phone. §8.4. */
    private static function forPersonalItem(object $user, ?string $country): ?string
    {
        if ($off = self::flagOff('individual_sell_enabled', $country)) {
            return $off;
        }

        return self::needsPhone($user, 'sell something');
    }

    /** Spec §7, §8.3: a one-time Neighbour Provider setup. */
    private static function forNeighbourOffer(object $user, ?string $country): ?string
    {
        if ($off = self::flagOff('neighbour_offers_enabled', $country)) {
            return $off;
        }

        if ($why = self::needsPhone($user, 'offer food or a service')) {
            return $why;
        }

        $has = DB::table('provider_members as m')
            ->join('provider_profiles as p', 'p.id', '=', 'm.provider_profile_id')
            ->where('m.user_id', $user->id)->where('m.status', 'active')
            ->where('p.provider_kind', 'neighbour_provider')
            ->whereNull('p.deleted_at')
            ->exists();

        // Not a refusal so much as the next step, and worded that way.
        return $has ? null : 'Set up your neighbour profile once, and then you can post offers.';
    }

    /** Spec §7: a provider or business profile. */
    private static function forBusinessListing(object $user, ?string $country): ?string
    {
        if ($off = self::flagOff('business_directory_enabled', $country)) {
            return $off;
        }

        return self::providersFor((int) $user->id) === []
            ? 'Create a business profile first, then you can advertise through it.'
            : null;
    }

    /** Spec §12.2: identity, phone, and a one-time activation. */
    private static function forCarpool(object $user, ?string $country): ?string
    {
        if ($off = self::flagOff('carpool_enabled', $country)) {
            return $off;
        }

        if ($why = self::needsPhone($user, 'post a journey')) {
            return $why;
        }

        // ⛔ Car Pool is the one capability that also needs identity. Spec
        // §12.2 requires confirmed identity for both drivers and passengers,
        // and it is the only place in this module where strangers get into a
        // car together.
        if ($user->identity_verification_status !== 'confirmed') {
            return 'Car Pool needs your identity confirmed first.';
        }

        if ($missing = self::notBuiltYet('carpool_profiles', 'Car Pool')) {
            return $missing;
        }

        return DB::table('carpool_profiles')->where('user_id', $user->id)->exists()
            ? null
            : 'Complete the one-time Car Pool setup before posting a journey.';
    }

    /** Spec §11: a professional profile with a verified credential. */
    private static function forProfessional(object $user, ?string $country): ?string
    {
        if ($off = self::flagOff('professional_services_enabled', $country)) {
            return $off;
        }

        if ($missing = self::notBuiltYet('professional_profiles', 'Professional services')) {
            return $missing;
        }

        $profileId = DB::table('professional_profiles')->where('user_id', $user->id)->value('id');

        if ($profileId === null) {
            return 'Create your professional profile first.';
        }

        // ⛔ A SUBMITTED CREDENTIAL IS NOT A VERIFIED ONE. Spec §11.5 keeps
        // "submitted", "under review", "verified from a document" and "verified
        // against an official register" as different states precisely so that
        // advertising cannot begin at the first of them.
        $verified = DB::table('verification_checks')
            ->where('subject_type', 'professional')
            ->where('subject_id', $profileId)
            ->where('verification_type', 'professional_credential')
            ->whereIn('status', ['verified_register', 'verified_document'])
            ->exists();

        return $verified ? null : 'Your credential has to be confirmed before you can advertise a regulated service.';
    }

    /* ---------------------------------------------------------- the helpers */

    private static function needsPhone(object $user, string $doing): ?string
    {
        if ($user->phone_verified_at !== null) {
            return null;
        }

        // Honest about where this stands: the gate is real, the way through it
        // is not built yet, and saying "confirm your number" when there is no
        // button to do that would be worse than saying nothing.
        return 'Confirm your phone number before you ' . $doing . '.';
    }

    /**
     * ⛔ A FLAG CAN BE SWITCHED ON BEFORE THE PHASE THAT BUILDS ITS TABLES.
     *
     * Car Pool is Phase 4 and Professional Services is Phase 3; their tables do
     * not exist yet. The flag check above normally stops us reaching them - both
     * are sensitive flags and default off - but an operator who enables one
     * early would otherwise get a 500 rather than an explanation, and would
     * reasonably conclude the site is broken rather than unfinished.
     */
    private static function notBuiltYet(string $table, string $what): ?string
    {
        static $known = [];

        if (!array_key_exists($table, $known)) {
            $known[$table] = \Illuminate\Support\Facades\Schema::hasTable($table);
        }

        return $known[$table] ? null : $what . ' is switched on but has not been built yet.';
    }

    private static function flagOff(string $flag, ?string $country): ?string
    {
        if (FeatureFlags::on($flag, $country)) {
            return null;
        }

        return 'That is not open here yet.';
    }
}

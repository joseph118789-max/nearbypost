<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;

/**
 * The Marketplace half of a person's public profile. Spec §5.
 *
 * ⛔ IT ADDS TO THE PROFILE THAT EXISTS; IT DOES NOT BUILD A SECOND ONE.
 *
 * `@{username}` already serves a community profile, and spec §3.1 turns on
 * there being ONE of them: "Every person uses the same NearbyPost account for
 * community news posting, comments, ratings, managing businesses, neighbour
 * offers, personal item sales, car-pool notices, professional profiles." A
 * separate /u/{username} for the commercial side would split the person in two
 * and the reader would have to know which half they were looking at.
 *
 * ⛔ AND NOTHING HERE PRODUCES A COMBINED SCORE. Spec §3.3 lists seven
 * reputations that must never merge. This class returns each in its own shape,
 * beside its own count, and there is deliberately no method that adds them up.
 */
class PersonProfile
{
    /**
     * Labels describing what somebody actually does here. Spec §5.1.
     *
     * ⛔ THESE ARE NOT BADGES AND MUST NEVER READ AS ONE. Spec §5.1: "Role
     * labels are informational, not quality badges." Each is earned by doing
     * the thing, not by being approved for it - which is why every one is
     * derived from live activity below rather than stored on the account where
     * somebody could grant one.
     */
    public static function roleLabels(int $userId): array
    {
        $labels = [];

        $posts = DB::table('news_items')
            ->where('contributor_id', $userId)->where('origin', 'user')
            ->where('status', 'active')->count();

        if ($posts > 0) {
            $labels[] = 'Community Reporter';
        }

        foreach (self::providers($userId) as $provider) {
            $label = match ($provider['kind']) {
                'neighbour_provider'  => 'Neighbour Provider',
                'registered_business' => 'Business Owner',
                'professional_firm', 'organisation' => 'Professional',
                default               => null,
            };

            if ($label !== null && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        $sells = DB::table('marketplace_listings')
            ->where('owner_user_id', $userId)->where('listing_kind', 'personal_item')
            ->whereNull('deleted_at')->exists();

        if ($sells) {
            $labels[] = 'Individual Seller';
        }

        if (DB::table('reviews')->where('reviewer_user_id', $userId)->where('status', 'published')->exists()) {
            $labels[] = 'Reviewer';
        }

        return $labels;
    }

    /**
     * The businesses and offers this person runs, each with its OWN image.
     *
     * ⛔ Spec §3.2: the personal avatar belongs to the person, and every
     * provider profile carries its own picture. Alex's community comments show
     * Alex; his renovation listings show the AT logo; his nasi lemak offers
     * show the nasi lemak. Returning the account's avatar for a provider here
     * would quietly merge the two identities the spec keeps apart.
     *
     * @return list<array{id: int, uuid: string, slug: string, name: string, kind: string,
     *                    kind_label: string, media_id: ?int, role: string, live_listings: int}>
     */
    public static function providers(int $userId): array
    {
        return DB::table('provider_members as m')
            ->join('provider_profiles as p', 'p.id', '=', 'm.provider_profile_id')
            ->where('m.user_id', $userId)
            ->where('m.status', 'active')
            ->whereNull('p.deleted_at')
            ->where('p.publication_status', 'published')
            ->orderBy('p.public_name')
            ->get(['p.id', 'p.public_uuid', 'p.slug', 'p.public_name', 'p.provider_kind',
                   'p.primary_media_id', 'm.role'])
            ->map(fn ($r) => [
                'id'         => (int) $r->id,
                'uuid'       => (string) $r->public_uuid,
                'slug'       => (string) $r->slug,
                'name'       => (string) $r->public_name,
                'kind'       => (string) $r->provider_kind,
                'kind_label' => VerificationPanel::kindLabel((string) $r->provider_kind),

                // The provider's own picture, never the person's avatar.
                'media_id'   => $r->primary_media_id === null ? null : (int) $r->primary_media_id,
                'role'       => (string) $r->role,
                'live_listings' => ListingSearch::visible()
                    ->where('l.provider_profile_id', $r->id)->count(),
            ])
            ->all();
    }

    /**
     * The Offers tab. Spec §5.2.
     *
     * Live listings across everything this person runs, plus their own personal
     * sales. One list, because a reader looking at Alex wants to know what Alex
     * is offering - not to visit three pages to find out.
     *
     * @return list<array<string, mixed>>
     */
    public static function offers(int $userId, int $limit = 30): array
    {
        $providerIds = array_column(self::providers($userId), 'id');

        return ListingSearch::visible()
            ->leftJoin('provider_profiles as p', 'p.id', '=', 'l.provider_profile_id')
            ->where(function ($q) use ($userId, $providerIds) {
                $q->where('l.owner_user_id', $userId);

                if ($providerIds !== []) {
                    $q->orWhereIn('l.provider_profile_id', $providerIds);
                }
            })
            ->orderByDesc('l.published_at')
            ->limit($limit)
            ->get(['l.public_uuid', 'l.slug', 'l.title', 'l.listing_kind', 'l.price_mode',
                   'l.price_min', 'l.price_max', 'l.currency', 'l.expires_at',
                   'p.public_name as provider', 'p.slug as provider_slug'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * The Reviews tab: ratings this person has WRITTEN. Spec §5.2.
     *
     * ⛔ Not ratings they have received. Those belong to the provider profile
     * or to the car-pool context that earned them, and putting them on a
     * personal page would be the merge §3.3 forbids - a reader would add them
     * up in their head even if the code did not.
     *
     * @return list<array<string, mixed>>
     */
    public static function reviewsWritten(int $userId, int $limit = 30): array
    {
        return DB::table('reviews as r')
            ->leftJoin('provider_profiles as p', function ($j) {
                $j->on('p.id', '=', 'r.target_id')->where('r.target_type', '=', 'provider');
            })
            ->where('r.reviewer_user_id', $userId)
            ->where('r.status', 'published')
            ->whereNull('r.deleted_at')
            ->orderByDesc('r.created_at')
            ->limit($limit)
            ->get(['r.public_uuid', 'r.overall_rating', 'r.body', 'r.context', 'r.created_at',
                   'p.public_name as about', 'p.slug as about_slug'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * What has been confirmed about the ACCOUNT. Spec §5.1, §3.4.
     *
     * The same discipline as a provider's panel: each line names one checked
     * fact, and a fact nobody checked is stated as not confirmed rather than
     * left out.
     *
     * @return list<array{says: string, confirmed: bool}>
     */
    public static function accountSignals(int $userId): array
    {
        $user = DB::table('users')->where('id', $userId)
            ->first(['phone_verified_at', 'identity_verification_status', 'created_at']);

        if ($user === null) {
            return [];
        }

        return [
            [
                'says'      => $user->phone_verified_at !== null ? 'Phone confirmed' : 'Phone not confirmed',
                'confirmed' => $user->phone_verified_at !== null,
            ],
            [
                'says'      => $user->identity_verification_status === 'confirmed'
                    ? 'Identity confirmed' : 'Identity not confirmed',
                'confirmed' => $user->identity_verification_status === 'confirmed',
            ],
            [
                // Spec §5.1 asks for the month and year, not the day: how long
                // somebody has been here is useful, when they joined is theirs.
                'says'      => 'Here since ' . \Carbon\Carbon::parse($user->created_at)->format('F Y'),
                'confirmed' => true,
            ],
        ];
    }

    /**
     * Everything the profile page needs from this module, in one call.
     *
     * @return array<string, mixed>
     */
    public static function forProfile(int $userId): array
    {
        return [
            'roleLabels'     => self::roleLabels($userId),
            'providers'      => self::providers($userId),
            'offers'         => self::offers($userId),
            'reviewsWritten' => self::reviewsWritten($userId),
            'accountSignals' => self::accountSignals($userId),
        ];
    }
}

<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writing, replying to and summarising ratings. Spec §16, §15.3.
 *
 * ⛔ THERE IS NO METHOD HERE THAT SUMS ACROSS CONTEXTS, AND THERE MUST NEVER BE.
 * Spec §3.3. A person's provider rating, seller rating, driver rating and
 * passenger rating are four separate figures about four different relationships,
 * and averaging them would produce a number that describes nobody.
 */
class Reviews
{
    /**
     * How much a target's own average is trusted against the prior.
     *
     * ⛔ THIS CONSTANT IS WHY ONE FIVE-STAR REVIEW CANNOT TOP A HUNDRED GOOD
     * ONES. Spec §15.3: "Never sort by raw average rating alone. A 5.0 from one
     * review must not automatically outrank 4.8 from 100 reviews."
     *
     * The weighted figure is a Bayesian average: the target's own ratings, plus
     * PRIOR_WEIGHT imaginary ratings at PRIOR_MEAN. With five ratings a single
     * perfect review is pulled most of the way back to the middle; by fifty the
     * prior barely matters, which is the right shape - confidence should follow
     * evidence.
     */
    private const PRIOR_WEIGHT = 5.0;
    private const PRIOR_MEAN   = 3.8;

    /**
     * @param  array{overall: int, dimensions?: array<string,int>, body?: ?string, ip?: ?string, device?: ?string}  $input
     *
     * @throws ReviewRefused
     */
    public static function write(int $userId, string $targetType, int $targetId, string $context, array $input): int
    {
        if (FeatureFlags::off('ratings_enabled')) {
            throw new ReviewRefused('Ratings are not open yet.');
        }

        if ($why = Capabilities::denies($userId, 'review')) {
            throw new ReviewRefused($why);
        }

        $overall = (int) ($input['overall'] ?? 0);

        if ($overall < 1 || $overall > 5) {
            throw new ReviewRefused('Choose a rating from one to five.');
        }

        self::refuseSelfReview($userId, $targetType, $targetId);

        // ⛔ Spec §16.5: a very low rating without a word of explanation is the
        // shape of a grudge, and a provider given one cannot answer it. The
        // rating still stands - this asks for a sentence, it does not refuse
        // the opinion.
        if ($overall <= 2 && trim((string) ($input['body'] ?? '')) === '') {
            throw new ReviewRefused('Say what went wrong, so the provider can answer it.');
        }

        $existing = DB::table('reviews')
            ->where('reviewer_user_id', $userId)
            ->where('target_type', $targetType)->where('target_id', $targetId)
            ->where('context', $context)->where('status', 'published')
            ->whereNull('deleted_at')
            ->first(['id']);

        if ($existing !== null) {
            throw new ReviewRefused('You have already rated this. Edit your review instead.');
        }

        $id = (int) DB::table('reviews')->insertGetId([
            'public_uuid'       => (string) Str::uuid(),
            'reviewer_user_id'  => $userId,
            'target_type'       => $targetType,
            'target_id'         => $targetId,
            'context'           => $context,
            'overall_rating'    => $overall,
            'dimension_ratings' => isset($input['dimensions']) ? json_encode($input['dimensions']) : null,
            'body'              => isset($input['body']) ? mb_substr((string) $input['body'], 0, 4000) : null,
            'status'            => 'published',
            'moderation_status' => 'not_checked',
            'ip_hash'           => empty($input['ip']) ? null : hash_hmac('sha256', $input['ip'], (string) config('app.key')),
            'device_token'      => $input['device'] ?? null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        self::recalculate($targetType, $targetId, $context);

        return $id;
    }

    /**
     * ⛔ NOBODY RATES THEMSELVES, AND NOBODY RATES A PROVIDER THEY WORK FOR.
     *
     * Spec §16.1 and §21.3. The second half matters more than the first: a
     * five-star review from the owner's own account is obvious, while one from
     * a "Support" member of the same provider looks exactly like a customer.
     * Membership is read from the database, never from the request.
     */
    private static function refuseSelfReview(int $userId, string $targetType, int $targetId): void
    {
        if (in_array($targetType, ['carpool_driver', 'carpool_passenger', 'individual_seller'], true)
            && $targetId === $userId) {
            throw new ReviewRefused('You cannot rate yourself.');
        }

        if ($targetType === 'provider') {
            $isMember = DB::table('provider_members')
                ->where('provider_profile_id', $targetId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            if ($isMember) {
                throw new ReviewRefused('You cannot rate a business you help run.');
            }
        }

        if ($targetType === 'listing') {
            $ownerId = DB::table('marketplace_listings')->where('id', $targetId)->value('owner_user_id');

            if ((int) $ownerId === $userId) {
                throw new ReviewRefused('You cannot rate your own listing.');
            }
        }
    }

    /**
     * A provider answers. Spec §16.1: they may reply, and may not edit or remove.
     *
     * @throws ReviewRefused
     */
    public static function reply(int $userId, int $reviewId, string $body): int
    {
        $review = DB::table('reviews')->where('id', $reviewId)->whereNull('deleted_at')
            ->first(['id', 'target_type', 'target_id', 'status']);

        if ($review === null || $review->status !== 'published') {
            throw new ReviewRefused('That review is no longer here.');
        }

        if ($review->target_type !== 'provider') {
            throw new ReviewRefused('Only a provider can reply to a review.');
        }

        $role = DB::table('provider_members')
            ->where('provider_profile_id', $review->target_id)
            ->where('user_id', $userId)->where('status', 'active')
            ->value('role');

        if (!in_array((string) $role, ['owner', 'manager', 'support'], true)) {
            throw new ReviewRefused('You do not speak for this business.');
        }

        $body = trim($body);

        if ($body === '') {
            throw new ReviewRefused('Write a reply first.');
        }

        return (int) DB::table('review_replies')->updateOrInsert(
            ['review_id' => $reviewId],
            [
                'author_user_id'      => $userId,
                'provider_profile_id' => $review->target_id,
                'body'                => mb_substr($body, 0, 2000),
                'status'              => 'published',
                'updated_at'          => now(),
                'created_at'          => now(),
            ]
        ) ? $reviewId : $reviewId;
    }

    /**
     * Recompute one target's summary. Idempotent; safe to run from a queue.
     */
    public static function recalculate(string $targetType, int $targetId, string $context): void
    {
        $rows = DB::table('reviews')
            ->where('target_type', $targetType)->where('target_id', $targetId)
            ->where('context', $context)->where('status', 'published')
            ->whereNull('deleted_at')
            ->get(['overall_rating', 'dimension_ratings']);

        $count = $rows->count();
        $mean  = $count > 0 ? $rows->avg('overall_rating') : null;

        // The Bayesian pull. With no reviews there is nothing to say; with a
        // few, the prior does most of the talking; with many, it stops
        // mattering.
        $weighted = $count > 0
            ? ((self::PRIOR_WEIGHT * self::PRIOR_MEAN) + ($mean * $count)) / (self::PRIOR_WEIGHT + $count)
            : null;

        $dimensions = [];

        foreach ($rows as $row) {
            foreach ((array) json_decode((string) $row->dimension_ratings, true) as $key => $value) {
                $dimensions[$key][] = (int) $value;
            }
        }

        $averages = [];

        foreach ($dimensions as $key => $values) {
            $averages[$key] = round(array_sum($values) / count($values), 2);
        }

        DB::table('rating_summaries')->updateOrInsert(
            ['target_type' => $targetType, 'target_id' => $targetId, 'context' => $context],
            [
                'review_count'       => $count,
                'average_rating'     => $mean === null ? null : round($mean, 2),
                'weighted_rating'    => $weighted === null ? null : round($weighted, 3),
                'dimension_averages' => $averages === [] ? null : json_encode($averages),
                'recalculated_at'    => now(),
                'updated_at'         => now(),
                'created_at'         => now(),
            ]
        );
    }

    /**
     * The line a reader is shown. Spec §16.4.
     *
     * ⛔ THE COUNT IS NEVER OPTIONAL, and the words are "community ratings" -
     * not "reviews from verified buyers", because NearbyPost never sees the
     * purchase. It happens on WhatsApp, between two people, and the site is not
     * a party to it.
     */
    public static function summaryLine(string $targetType, int $targetId, string $context): ?string
    {
        $row = DB::table('rating_summaries')
            ->where('target_type', $targetType)->where('target_id', $targetId)
            ->where('context', $context)
            ->first(['review_count', 'average_rating']);

        if ($row === null || (int) $row->review_count === 0) {
            return null;   // "no ratings yet" is the caller's wording to choose
        }

        return sprintf('%s from %d community %s',
            rtrim(rtrim(number_format((float) $row->average_rating, 1), '0'), '.') ?: '0',
            (int) $row->review_count,
            (int) $row->review_count === 1 ? 'rating' : 'ratings');
    }
}

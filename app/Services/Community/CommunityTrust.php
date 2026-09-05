<?php

namespace App\Services\Community;

use Illuminate\Support\Facades\DB;

/**
 * Reactions, reports, trust status and the reputation ledger.
 *
 * Points reward activity; credibility is trust. Every change is a ledger
 * row keyed (user, post, event), so a retry can never award twice. Weights
 * per actor are the guide's starting values, recorded with a scoring version.
 */
final class CommunityTrust
{
    public const SCORING = '1';
    public const RULES   = '1';

    public const CONFIRM_AT = 3.0;   // weighted "I saw this too"
    public const DISPUTE_AT = 2.0;   // weighted "something is wrong" + reports, and more than the confirmations
    public const RECHECK_AT = [5.0, 15.0, 40.0];

    /** The weight of one actor's voice. */
    public static function weightFor(?object $user, bool $anonymous): float
    {
        if ($anonymous || $user === null) {
            return 0.1;
        }

        if (!empty($user->trusted_at) || (int) ($user->credibility ?? 50) >= 80) {
            return 1.5;
        }

        $age = isset($user->created_at) ? abs(now()->diffInDays($user->created_at)) : 0;   // Carbon 3 signs the difference: a past date is negative

        return $age < 7 ? 0.5 : 1.0;
    }

    public static function levelName(int $credibility): string
    {
        return match (true) {
            $credibility < 20 => 'Restricted',
            $credibility < 40 => 'New contributor',
            $credibility < 60 => 'Contributor',
            $credibility < 80 => 'Established contributor',
            default           => 'Trusted local contributor',
        };
    }

    /** Record a reaction (one per user per type; anonymous by device token) and refresh the post's status. */
    public static function react(int $newsItemId, string $type, ?object $user, ?string $deviceToken, ?string $ipHash): array
    {
        $weight = self::weightFor($user, $user === null);
        $q = DB::table('community_reactions')->where('news_item_id', $newsItemId)->where('type', $type);
        $q = $user ? $q->where('user_id', $user->id) : $q->where('device_token', $deviceToken);

        if ($q->exists()) {
            $q->delete();   // a second press withdraws it
            $toggled = 'removed';
        } else {
            // one voice per reader per report: choosing "Helpful" after "I saw this too" replaces it (owner, 3 Sep)
            $others = DB::table('community_reactions')->where('news_item_id', $newsItemId);
            ($user ? $others->where('user_id', $user->id) : $others->where('device_token', $deviceToken))->delete();

            DB::table('community_reactions')->insert([
                'news_item_id' => $newsItemId, 'user_id' => $user?->id, 'device_token' => $user ? null : $deviceToken, 'ip_hash' => $ipHash,
                'type' => $type, 'weight' => $weight, 'scoring_version' => self::SCORING, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $toggled = 'added';
        }

        self::refresh($newsItemId, 'reaction');

        return ['result' => $toggled, 'weight' => $weight];
    }

    /** Record a report; the first meaningful one (or a threshold) asks for a re-review. */
    public static function report(int $newsItemId, string $reason, ?string $explanation, ?string $evidence, ?object $user, ?string $deviceToken, ?string $ipHash): array
    {
        $weight = self::weightFor($user, $user === null);
        DB::table('community_reports')->insert([
            'news_item_id' => $newsItemId, 'user_id' => $user?->id, 'device_token' => $user ? null : $deviceToken, 'ip_hash' => $ipHash,
            'reason' => $reason, 'explanation' => $explanation ? mb_substr($explanation, 0, 1000) : null, 'evidence_url' => $evidence ? mb_substr($evidence, 0, 500) : null,
            'weight' => $weight, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $meta = self::refresh($newsItemId, 'report');
        $total = (float) $meta->report_weight;
        $count = (int) $meta->report_count;

        // the first report of any weight, a trusted reporter, new evidence, a threshold crossed, or ~50 reports
        $recheck = $count === 1 || $weight >= 1.5 || $evidence
            || in_array(true, array_map(fn ($th) => $total >= $th && $total - $weight < $th, self::RECHECK_AT), true)
            || $count % 50 === 0;

        if ($recheck) {
            \App\Jobs\ReReviewCommunityPost::dispatch($newsItemId, $reason, $explanation)->onQueue('default');
        }

        return ['weight' => $weight, 'recheck' => $recheck];
    }

    /** Recompute weighted totals and the trust status from them. */
    public static function refresh(int $newsItemId, string $why): object
    {
        $sum = fn ($type) => (float) DB::table('community_reactions')->where('news_item_id', $newsItemId)->where('type', $type)->sum('weight');
        $reports = DB::table('community_reports')->where('news_item_id', $newsItemId)->where('status', '<>', 'rejected');
        $saw = $sum('saw'); $helpful = $sum('helpful'); $wrong = $sum('wrong');
        $reportWeight = (float) $reports->sum('weight'); $reportCount = (int) $reports->count();

        $meta = DB::table('community_post_meta')->where('news_item_id', $newsItemId)->first();

        if (!$meta) {
            throw new \RuntimeException('no community meta for ' . $newsItemId);
        }

        $status = $meta->trust_status;

        if (!in_array($status, ['removed', 'corrected', 'expired'], true)) {
            if ($wrong + $reportWeight >= self::DISPUTE_AT && $wrong + $reportWeight > $saw) {
                $status = 'disputed';
            } elseif ($saw >= self::CONFIRM_AT) {
                $status = 'confirmed';
            } else {
                $status = 'unverified';
            }
        }

        DB::table('community_post_meta')->where('news_item_id', $newsItemId)->update([
            'saw_weight' => $saw, 'helpful_weight' => $helpful, 'wrong_weight' => $wrong,
            'report_weight' => $reportWeight, 'report_count' => $reportCount, 'trust_status' => $status, 'updated_at' => now(),
        ]);

        if ($status !== $meta->trust_status) {
            self::history($newsItemId, 'trust_status', $meta->trust_status, $status, 'system', null, $why);
            $author = DB::table('news_items')->where('id', $newsItemId)->value('contributor_id');

            if ($author) {
                if ($status === 'confirmed') {
                    self::ledger((int) $author, $newsItemId, 'confirmed', 3, 4, 'community confirmed the report');
                } elseif ($status === 'disputed') {
                    self::ledger((int) $author, $newsItemId, 'disputed', -2, -3, 'the community disputed the report');
                }
            }
        }

        return DB::table('community_post_meta')->where('news_item_id', $newsItemId)->first();
    }

    /** One ledger row per (user, post, event); a repeat is ignored, so nothing is ever awarded twice. */
    public static function ledger(int $userId, ?int $newsItemId, string $event, int $points, int $credibility, ?string $note = null): bool
    {
        $exists = DB::table('community_reputation_events')->where('user_id', $userId)->where('news_item_id', $newsItemId)->where('event', $event)->exists();

        if ($exists) {
            return false;
        }

        DB::table('community_reputation_events')->insert([
            'user_id' => $userId, 'news_item_id' => $newsItemId, 'event' => $event, 'points_delta' => $points, 'credibility_delta' => $credibility,
            'rules_version' => self::RULES, 'note' => $note, 'created_at' => now(),
        ]);

        $sums = DB::table('community_reputation_events')->where('user_id', $userId)
            ->selectRaw('coalesce(sum(points_delta),0) p, coalesce(sum(credibility_delta),0) c')->first();
        $base = (int) (DB::table('users')->where('id', $userId)->value('credibility_base') ?? 50);
        DB::table('users')->where('id', $userId)->update([
            'points' => (int) $sums->p, 'credibility' => max(0, min(100, $base + (int) $sums->c)), 'updated_at' => now(),
        ]);

        return true;
    }

    public static function history(int $newsItemId, string $field, ?string $from, string $to, string $actorType, ?int $actorId, ?string $reason): void
    {
        DB::table('community_status_history')->insert([
            'news_item_id' => $newsItemId, 'field' => $field, 'from' => $from, 'to' => $to,
            'actor_type' => $actorType, 'actor_id' => $actorId, 'reason' => $reason ? mb_substr($reason, 0, 400) : null, 'created_at' => now(),
        ]);
    }

    /** Set a trust/moderation status by a person or the system, with the story's visibility following it. */
    public static function setStatus(int $newsItemId, string $trust, string $actorType, ?int $actorId, string $reason): void
    {
        $meta = DB::table('community_post_meta')->where('news_item_id', $newsItemId)->first();

        if (!$meta || $meta->trust_status === $trust) {
            return;
        }

        DB::table('community_post_meta')->where('news_item_id', $newsItemId)->update([
            'trust_status' => $trust, 'removed_at' => $trust === 'removed' ? now() : null, 'updated_at' => now(),
            'seo_eligibility' => $trust === 'removed' ? 'none' : $meta->seo_eligibility,
        ]);
        self::history($newsItemId, 'trust_status', $meta->trust_status, $trust, $actorType, $actorId, $reason);

        // the story itself: off the site when removed, back when restored
        DB::table('news_items')->where('id', $newsItemId)->update([
            'status' => $trust === 'removed' ? 'held' : 'active',
            'review_status' => $trust === 'removed' ? 'removed' : 'published',
            'updated_at' => now(),
        ]);

        $author = DB::table('news_items')->where('id', $newsItemId)->value('contributor_id');

        if ($author && $trust === 'removed') {
            self::ledger((int) $author, $newsItemId, 'removed', -10, -8, $reason);
        }
    }
}

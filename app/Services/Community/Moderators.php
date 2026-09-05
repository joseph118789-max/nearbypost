<?php

namespace App\Services\Community;

use Illuminate\Support\Facades\DB;

/**
 * Trusted community moderators: a role below staff with narrow, reversible,
 * logged permissions. They triage reports, mark duplicates, recommend
 * corrections, escalate, and reduce distribution (hold). They cannot ban,
 * see private GPS or device data, erase history, or decide appeals.
 */
final class Moderators
{
    public const ACTIONS = ['reject_reports', 'mark_duplicate', 'recommend_correction', 'escalate', 'hold', 'unhold', 'hide_comment'];
    public const REASON_CODES = ['duplicate', 'false', 'misleading', 'wrong_place', 'spam', 'harassment', 'safety', 'resolved', 'other'];

    public static function isModerator(?object $user): bool
    {
        return $user !== null && ($user->community_role ?? null) === 'moderator';
    }

    public static function eligible(object $user): bool
    {
        return (int) ($user->credibility ?? 0) >= 80 && !empty($user->trusted_at) && abs(now()->diffInDays($user->created_at)) >= 30;
    }

    public static function act(object $mod, string $action, int $newsItemId, string $reasonCode, string $reason, ?int $commentId = null, ?string $value = null): string
    {
        if (!in_array($action, self::ACTIONS, true) || !in_array($reasonCode, self::REASON_CODES, true)) {
            return 'That action is not one a community moderator can take.';
        }

        $id = DB::table('community_moderator_actions')->insertGetId([
            'user_id' => $mod->id, 'news_item_id' => $newsItemId, 'comment_id' => $commentId, 'action' => $action,
            'reason_code' => $reasonCode, 'reason' => mb_substr($reason, 0, 400), 'created_at' => now(),
        ]);

        switch ($action) {
            case 'reject_reports':
                DB::table('community_reports')->where('news_item_id', $newsItemId)->where('status', 'open')->update(['status' => 'rejected', 'updated_at' => now()]);
                CommunityTrust::refresh($newsItemId, 'reports triaged by a community moderator: ' . $reason);
                return 'Open reports rejected.';
            case 'mark_duplicate':
                if ($value && preg_match('/(\d+)/', $value, $m)) {
                    DB::table('news_items')->where('id', $newsItemId)->update(['duplicate_of' => (int) $m[1], 'duplicate_reason' => 'community moderator #' . $id, 'updated_at' => now()]);
                    CommunityTrust::history($newsItemId, 'duplicate_of', null, (string) $m[1], 'moderator', $mod->id, $reason);
                }
                return 'Marked as a duplicate.';
            case 'recommend_correction':
                DB::table('community_corrections')->insert(['news_item_id' => $newsItemId, 'user_id' => $mod->id, 'field' => 'body', 'proposed_value' => (string) $value,
                    'explanation' => $reason, 'proposer_weight' => 1.5, 'created_at' => now(), 'updated_at' => now()]);
                return 'Correction recommended; the author or staff decides.';
            case 'escalate':
                foreach (DB::table('admins')->pluck('id') as $adminId) { /* staff see it in the desk; nothing else needed */ }
                DB::table('community_post_meta')->where('news_item_id', $newsItemId)->update(['moderation_status' => 'held', 'updated_at' => now()]);
                DB::table('news_items')->where('id', $newsItemId)->update(['status' => 'held', 'review_status' => 'pending_review', 'review_reason' => 'escalated by a community moderator: ' . mb_substr($reason, 0, 200), 'updated_at' => now()]);
                CommunityTrust::history($newsItemId, 'moderation_status', 'published', 'held', 'moderator', $mod->id, 'escalated: ' . $reason);
                return 'Escalated to staff and hidden meanwhile.';
            case 'hold':
                DB::table('community_post_meta')->where('news_item_id', $newsItemId)->update(['moderation_status' => 'held', 'updated_at' => now()]);
                DB::table('news_items')->where('id', $newsItemId)->update(['status' => 'held', 'review_status' => 'pending_review', 'review_reason' => mb_substr($reason, 0, 300), 'updated_at' => now()]);
                CommunityTrust::history($newsItemId, 'moderation_status', 'published', 'held', 'moderator', $mod->id, $reason);
                return 'Held.';
            case 'unhold':
                DB::table('community_post_meta')->where('news_item_id', $newsItemId)->update(['moderation_status' => 'published', 'updated_at' => now()]);
                DB::table('news_items')->where('id', $newsItemId)->update(['status' => 'active', 'review_status' => 'published', 'updated_at' => now()]);
                CommunityTrust::history($newsItemId, 'moderation_status', 'held', 'published', 'moderator', $mod->id, $reason);
                return 'Published again.';
            case 'hide_comment':
                if ($commentId) {
                    DB::table('community_comments')->where('id', $commentId)->update(['status' => 'hidden', 'moderation_note' => 'hidden by a community moderator: ' . mb_substr($reason, 0, 200), 'updated_at' => now()]);
                }
                return 'Comment hidden.';
        }

        return 'Done.';
    }
}

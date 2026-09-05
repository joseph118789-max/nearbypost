<?php

namespace App\Services\Community;

use Illuminate\Support\Facades\DB;

/**
 * In-app notifications, deduplicated by key and clustered by event. A user
 * with notify_mode "none" gets nothing; "daily" and "immediate" both land
 * here (there is no mail yet) and differ only in what the page shows first.
 */
final class Notifications
{
    public static function send(int $userId, string $type, string $title, ?string $body, ?string $url, ?int $newsItemId = null, ?int $commentId = null, ?string $dedupe = null): bool
    {
        $mode = DB::table('users')->where('id', $userId)->value('notify_mode') ?? 'daily';

        if ($mode === 'none') {
            return false;
        }

        $dedupe = $dedupe ?? ($type . ':' . ($newsItemId ?? '') . ':' . ($commentId ?? ''));

        return (bool) DB::table('community_notifications')->insertOrIgnore([
            'user_id' => $userId, 'type' => $type, 'news_item_id' => $newsItemId, 'comment_id' => $commentId,
            'title' => mb_substr($title, 0, 200), 'body' => $body ? mb_substr($body, 0, 500) : null, 'url' => $url,
            'dedupe_key' => mb_substr($dedupe, 0, 120), 'created_at' => now(),
        ]);
    }

    public static function unread(int $userId): int
    {
        return DB::table('community_notifications')->where('user_id', $userId)->whereNull('read_at')->count();
    }

    /**
     * A report was published: tell the followers of its author, of its area
     * (within radius, optionally the same category), and of its category.
     * One notification per user however many rules matched.
     */
    public static function onPublished(object $item): void
    {
        if ($item->latitude === null || !$item->contributor_id) {
            return;
        }

        $title = mb_substr((string) $item->title, 0, 120);
        $url = url('/post/' . $item->id);
        $sent = [];

        // followers of the author
        $author = DB::table('users')->where('id', $item->contributor_id)->first(['username']);

        foreach (DB::table('user_follows')->where('followed_id', $item->contributor_id)->pluck('follower_id') as $uid) {
            if (self::send((int) $uid, 'followed_author', '@' . ($author->username ?? 'someone') . ' posted nearby: ' . $title, null, $url, $item->id, null, 'post:' . $item->id)) {
                $sent[(int) $uid] = true;
            }
        }

        // area follows: distance in km by the haversine formula
        $lat = (float) $item->latitude; $lng = (float) $item->longitude;
        $areas = DB::table('area_follows')->where('paused', false)
            ->whereRaw('(6371 * acos(least(1.0, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) <= radius_km', [$lat, $lng, $lat])
            ->get();

        foreach ($areas as $a) {
            if ($a->category && $a->category !== $item->primary_category) {
                continue;
            }

            if (isset($sent[(int) $a->user_id]) || (int) $a->user_id === (int) $item->contributor_id) {
                continue;
            }

            if (self::send((int) $a->user_id, 'followed_area', 'New report near ' . $a->label . ': ' . $title, null, $url, $item->id, null, 'post:' . $item->id)) {
                $sent[(int) $a->user_id] = true;
            }
        }

        // topic follows
        if ($item->primary_category) {
            foreach (DB::table('topic_follows')->where('paused', false)->where('category', $item->primary_category)->pluck('user_id') as $uid) {
                if (!isset($sent[(int) $uid]) && (int) $uid !== (int) $item->contributor_id) {
                    self::send((int) $uid, 'followed_topic', $item->primary_category . ': ' . $title, null, $url, $item->id, null, 'post:' . $item->id);
                }
            }
        }
    }
}

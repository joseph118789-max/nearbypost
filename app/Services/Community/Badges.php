<?php

namespace App\Services\Community;

use Illuminate\Support\Facades\DB;

/**
 * Badges are measured behaviour, recalculated from the ledger and the
 * reports every time; a badge whose rule no longer holds is revoked, not
 * kept. Leaderboards rank validated contribution, never raw volume.
 */
final class Badges
{
    public const RULES = [
        'first_report'        => 'First Report',
        'ten_confirmed'       => '10 Community-Confirmed Reports',
        'trusted_local'       => 'Trusted Local Contributor',
        'accurate_reporter'   => 'Accurate Reporter',
        'helpful_corrector'   => 'Helpful Corrector',
        'emergency_contributor' => 'Emergency Contributor',
        'area_contributor'    => 'Area Contributor',
    ];

    /** @return list<array{badge: string, name: string, detail: ?string}> */
    public static function recalculate(int $userId): array
    {
        $posts = DB::table('news_items as n')->join('community_post_meta as m', 'm.news_item_id', '=', 'n.id')
            ->where('n.contributor_id', $userId)->where('n.origin', 'user')->where('n.status', 'active')
            ->get(['n.id', 'm.trust_status', 'm.breaking', 'n.location_label', 'n.main_place_text']);
        $confirmed = $posts->where('trust_status', 'confirmed')->count();
        $disputed  = DB::table('news_items as n')->join('community_post_meta as m', 'm.news_item_id', '=', 'n.id')
            ->where('n.contributor_id', $userId)->whereIn('m.trust_status', ['disputed', 'removed'])->count();
        $cred = (int) (DB::table('users')->where('id', $userId)->value('credibility') ?? 50);
        $corrections = DB::table('community_corrections')->where('user_id', $userId)->where('status', 'accepted')->count();
        $breaking = $posts->where('breaking', true)->count();

        $want = [];
        if ($posts->count() >= 1) { $want['first_report'] = null; }
        if ($confirmed >= 10) { $want['ten_confirmed'] = null; }
        if ($cred >= 80 && $posts->count() >= 5) { $want['trusted_local'] = null; }
        if ($confirmed >= 5 && $disputed === 0) { $want['accurate_reporter'] = null; }
        if ($corrections >= 3) { $want['helpful_corrector'] = null; }
        if ($breaking >= 3) { $want['emergency_contributor'] = null; }

        // area: five or more validated reports whose place label shares the same town
        $areas = [];
        foreach ($posts->whereIn('trust_status', ['confirmed', 'unverified']) as $p) {
            $town = self::town($p->location_label ?: $p->main_place_text);
            if ($town !== null) { $areas[$town] = ($areas[$town] ?? 0) + 1; }
        }
        foreach ($areas as $town => $n) {
            if ($n >= 5) { $want['area_contributor:' . $town] = $town; }
        }

        $have = DB::table('user_community_badges')->where('user_id', $userId)->whereNull('revoked_at')->get();
        $haveKeys = $have->map(fn ($b) => $b->badge . ($b->detail ? ':' . $b->detail : ''))->all();

        foreach ($want as $key => $detail) {
            $badge = explode(':', $key)[0];
            if (!in_array($key, $haveKeys, true)) {
                DB::table('user_community_badges')->updateOrInsert(
                    ['user_id' => $userId, 'badge' => $badge, 'detail' => $detail],
                    ['awarded_at' => now(), 'revoked_at' => null]
                );
                Notifications::send($userId, 'badge', 'You earned a badge: ' . self::RULES[$badge] . ($detail ? ' (' . $detail . ')' : ''), null, url('/@' . (DB::table('users')->where('id', $userId)->value('username') ?? '')), null, null, 'badge:' . $key);
            }
        }

        foreach ($have as $b) {
            $key = $b->badge . ($b->detail ? ':' . $b->detail : '');
            if (!array_key_exists($key, $want)) {
                DB::table('user_community_badges')->where('id', $b->id)->update(['revoked_at' => now()]);
            }
        }

        return DB::table('user_community_badges')->where('user_id', $userId)->whereNull('revoked_at')->orderBy('awarded_at')->get()
            ->map(fn ($b) => ['badge' => $b->badge, 'name' => self::RULES[$b->badge] ?? $b->badge, 'detail' => $b->detail])->all();
    }

    /** The town in a place label: the second-to-last part, or the last when there is one part. */
    public static function town(?string $label): ?string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $label))));

        if ($parts === []) {
            return null;
        }

        return count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];
    }

    /**
     * Validated contribution: confirmed x3, published x1, disputed -2, accepted corrections x1,
     * over a window; minimum two published reports; capped at 20 published per user per window.
     *
     * @return list<array{user_id: int, username: string, score: int, reports: int, confirmed: int}>
     */
    public static function leaderboard(?string $town, string $window): array
    {
        $since = match ($window) { 'weekly' => now()->subDays(7), 'monthly' => now()->subDays(30), default => null };
        $rows = DB::table('news_items as n')->join('community_post_meta as m', 'm.news_item_id', '=', 'n.id')->join('users as u', 'u.id', '=', 'n.contributor_id')
            ->where('n.origin', 'user')->where('n.status', 'active')->whereNotNull('u.username')
            ->when($since, fn ($q) => $q->where('n.published_at', '>=', $since))
            ->get(['n.contributor_id', 'u.username', 'm.trust_status', 'n.location_label', 'n.main_place_text']);

        $by = [];
        foreach ($rows as $r) {
            if ($town !== null && strcasecmp((string) self::town($r->location_label ?: $r->main_place_text), $town) !== 0) { continue; }
            $b = &$by[$r->contributor_id];
            $b ??= ['user_id' => (int) $r->contributor_id, 'username' => $r->username, 'score' => 0, 'reports' => 0, 'confirmed' => 0];
            if ($b['reports'] >= 20) { continue; }   // anti-gaming cap
            $b['reports']++;
            $b['score'] += match ($r->trust_status) { 'confirmed' => 3, 'disputed', 'removed' => -2, default => 1 };
            if ($r->trust_status === 'confirmed') { $b['confirmed']++; }
        }
        unset($b);

        $corr = DB::table('community_corrections')->where('status', 'accepted')->when($since, fn ($q) => $q->where('resolved_at', '>=', $since))
            ->selectRaw('user_id, count(*) n')->groupBy('user_id')->pluck('n', 'user_id');
        foreach ($corr as $uid => $n) {
            if (isset($by[$uid])) { $by[$uid]['score'] += min((int) $n, 5); }
        }

        $list = array_values(array_filter($by, fn ($b) => $b['reports'] >= 2));
        usort($list, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $b['confirmed'] <=> $a['confirmed']);

        return array_slice($list, 0, 50);
    }
}

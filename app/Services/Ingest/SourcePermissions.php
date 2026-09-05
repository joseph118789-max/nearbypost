<?php

namespace App\Services\Ingest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a publisher agreed we may show, and how they take it back.
 *
 * ⛔⛔ CONSENT WITHOUT SCOPE IS NOT CONSENT. "You may show my content" says
 * nothing about whether a photograph may be used or only a headline, and a
 * publisher who meant the second has agreed to the first by accident. So the
 * scope is four separate answers, recorded with the wording they agreed to and
 * the moment they agreed.
 *
 * ⭐ It is also what makes revocation mean anything. Without a recorded scope
 * there is nothing to withdraw and no way to say what should come down.
 *
 * ⛔ THE DEFAULT FOR IMAGES IS NO. Everything else here defaults to yes because
 * a headline, a short excerpt and a summary with a link back are the ordinary
 * terms of a news aggregator. A photograph is somebody's licensed work, often
 * not even the publisher's to give, so it is off unless someone says otherwise.
 */
class SourcePermissions
{
    /** Bumped when the consent wording changes, so old agreements stay readable. */
    public const CONSENT_VERSION = '2026-09-05';

    public const CONSENT_TEXT =
        'I confirm I run this site or can speak for whoever does, and I am asking Nearbypost to '
        . 'show the parts I have ticked, always with a link back to the original. I understand '
        . 'Nearbypost may decline, pause or remove anything, and that I can withdraw at any time.';

    /** @return array<string, bool> */
    public static function fromInput(array $input): array
    {
        return [
            'allow_headline'   => true,   // there is nothing to show without it
            'allow_excerpt'    => (bool) ($input['allow_excerpt'] ?? true),
            'allow_ai_summary' => (bool) ($input['allow_ai_summary'] ?? true),
            'allow_image'      => (bool) ($input['allow_image'] ?? false),
        ];
    }

    /**
     * What a reader may be shown for one story, given its source.
     *
     * ⛔ Asked HERE rather than in a template. A permission checked in one
     * template and forgotten in another is the same class of bug as a category
     * resolved two different ways - and this one has legal weight.
     *
     * @return array{headline: bool, excerpt: bool, summary: bool, image: bool, revoked: bool}
     */
    public static function forSource(?string $sourceName): array
    {
        static $cache = [];

        if ($sourceName === null || $sourceName === '') {
            return self::open();
        }

        if (isset($cache[$sourceName])) {
            return $cache[$sourceName];
        }

        $row = DB::table('sources')
            ->where('name', $sourceName)
            ->orWhere('name', 'like', $sourceName . ' - %')
            ->first(['source_type', 'allow_headline', 'allow_excerpt', 'allow_ai_summary',
                     'allow_image', 'revoked_at']);

        // A source we found ourselves has no agreement to honour; the ordinary
        // rules of the site apply. Only a contributed one carries a scope.
        if ($row === null || $row->source_type !== 'contributed') {
            return $cache[$sourceName] = self::open();
        }

        if ($row->revoked_at !== null) {
            return $cache[$sourceName] = [
                'headline' => false, 'excerpt' => false, 'summary' => false,
                'image' => false, 'revoked' => true,
            ];
        }

        return $cache[$sourceName] = [
            'headline' => (bool) $row->allow_headline,
            'excerpt'  => (bool) $row->allow_excerpt,
            'summary'  => (bool) $row->allow_ai_summary,
            'image'    => (bool) $row->allow_image,
            'revoked'  => false,
        ];
    }

    /**
     * A publisher withdrawing.
     *
     * ⛔ TWO THINGS, NOT ONE. Stopping the fetch is the easy half; what is
     * already on the site is the half that matters to them. Both happen here,
     * in one transaction, so a half-done revocation cannot exist.
     *
     * @return array{ok: bool, why?: string, stopped?: int}
     */
    public static function revoke(string $sourceName, string $reason, ?int $adminId = null): array
    {
        if (trim($reason) === '') {
            return ['ok' => false, 'why' => 'Say why, so the record can be explained.'];
        }

        $source = DB::table('sources')->where('name', $sourceName)->first(['id', 'name']);

        if ($source === null) {
            return ['ok' => false, 'why' => 'No such source.'];
        }

        return DB::transaction(function () use ($source, $reason, $adminId) {
            DB::table('sources')->where('id', $source->id)->update([
                'is_active'      => false,
                'revoked_at'     => now(),
                'revoked_reason' => mb_substr(trim($reason), 0, 300),
                'updated_at'     => now(),
            ]);

            // Off the site. Not deleted - the rows are the record of what was
            // shown and when, which is exactly what a rights complaint needs.
            $stopped = DB::table('feed_ready_items')
                ->whereIn('news_item_id', DB::table('news_items')
                    ->where('source', $source->name)->select('id'))
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            DB::table('audit_events')->insert([
                'event'          => 'source.revoked',
                'subject_type'   => 'source',
                'subject_id'     => $source->id,
                'actor_admin_id' => $adminId,
                'after'          => json_encode(['stopped_serving' => $stopped]),
                'reason'         => mb_substr(trim($reason), 0, 300),
                'created_at'     => now(),
            ]);

            return ['ok' => true, 'stopped' => $stopped];
        });
    }

    /** A link a publisher can use to withdraw without having to ask us. */
    public static function revokeToken(int $sourceId): string
    {
        $token = DB::table('sources')->where('id', $sourceId)->value('revoke_token');

        if ($token) {
            return $token;
        }

        $token = 'rv-' . Str::lower(Str::random(32));
        DB::table('sources')->where('id', $sourceId)->update(['revoke_token' => $token, 'updated_at' => now()]);

        return $token;
    }

    /** @return array{headline: bool, excerpt: bool, summary: bool, image: bool, revoked: bool} */
    private static function open(): array
    {
        return ['headline' => true, 'excerpt' => true, 'summary' => true,
                'image' => true, 'revoked' => false];
    }
}

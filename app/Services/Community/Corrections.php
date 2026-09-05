<?php

namespace App\Services\Community;

use Illuminate\Support\Facades\DB;

/** Structured correction proposals; an accepted one is a new version with a public note and a ledger entry. */
final class Corrections
{
    public const FIELDS = ['title', 'body', 'location', 'time', 'event_status', 'duplicate'];

    public static function accept(int $correctionId, string $byType, ?int $byId, ?string $note = null): void
    {
        $c = DB::table('community_corrections')->where('id', $correctionId)->first();

        if (!$c || $c->status !== 'open') {
            return;
        }

        $item = DB::table('news_items')->where('id', $c->news_item_id)->first();

        if (!$item) {
            return;
        }

        $update = ['updated_at' => now()];

        switch ($c->field) {
            case 'title':    $update['title'] = mb_substr($c->proposed_value, 0, 200); break;
            case 'body':     $update['body']  = mb_substr($c->proposed_value, 0, 5000); break;
            case 'location': $update['main_place_text'] = mb_substr($c->proposed_value, 0, 160); $update['location_label'] = mb_substr($c->proposed_value, 0, 160); break;
            case 'event_status': $update['body'] = (string) $item->body . "\n\nUpdate: " . mb_substr($c->proposed_value, 0, 300); break;
            case 'duplicate':
                if (preg_match('/(\d+)/', $c->proposed_value, $m)) { $update['duplicate_of'] = (int) $m[1]; $update['duplicate_reason'] = 'community correction'; }
                break;
            default: break;   // time: recorded in the note, not on the row
        }

        DB::table('news_items')->where('id', $item->id)->update($update);
        DB::table('feed_ready_items')->where('news_item_id', $item->id)->update(array_intersect_key($update, ['title' => 1, 'location_label' => 1]) + ['updated_at' => now()]);

        DB::table('community_post_versions')->insert([
            'news_item_id' => $item->id, 'actor_type' => $byType, 'actor_id' => $byId,
            'title' => $update['title'] ?? $item->title, 'body' => $update['body'] ?? (string) $item->body,
            'reason' => 'correction accepted: ' . $c->field . ($note ? ' - ' . mb_substr($note, 0, 200) : ''), 'created_at' => now(),
        ]);
        DB::table('community_post_meta')->where('news_item_id', $item->id)->update([
            'trust_status' => 'corrected', 'last_materially_updated_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('community_corrections')->where('id', $correctionId)->update([
            'status' => 'accepted', 'resolved_by' => $byId, 'resolved_by_type' => $byType, 'resolution_note' => $note, 'resolved_at' => now(), 'updated_at' => now(),
        ]);
        CommunityTrust::history($item->id, 'trust_status', null, 'corrected', $byType, $byId, 'correction #' . $correctionId . ' (' . $c->field . ') accepted');
        CommunityTrust::ledger((int) $c->user_id, $item->id, 'correction_accepted:' . $correctionId, 1, 2, 'a correction was accepted');
        Notifications::send((int) $c->user_id, 'correction_accepted', 'Your correction was accepted', mb_substr((string) $item->title, 0, 120), url('/post/' . $item->id), $item->id);

        if ($item->contributor_id && (int) $item->contributor_id !== (int) $c->user_id) {
            Notifications::send((int) $item->contributor_id, 'post_corrected', 'Your report was corrected', 'Field: ' . $c->field, url('/post/' . $item->id), $item->id);
        }

        // translations were made from the old text: mark them for regeneration
        DB::table('news_translations')->where('news_item_id', $item->id)->update(['updated_at' => now(), 'model' => 'stale']);
        Badges::recalculate((int) $c->user_id);
    }

    public static function reject(int $correctionId, string $byType, ?int $byId, ?string $note = null): void
    {
        DB::table('community_corrections')->where('id', $correctionId)->where('status', 'open')->update([
            'status' => 'rejected', 'resolved_by' => $byId, 'resolved_by_type' => $byType, 'resolution_note' => $note, 'resolved_at' => now(), 'updated_at' => now(),
        ]);
    }
}

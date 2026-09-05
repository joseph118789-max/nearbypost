<?php

namespace App\Services\Community;

use App\Models\NewsItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * After PostPublisher has judged a reader's story, this records the
 * community-report side of it: the immutable original, the pin's provenance,
 * the editor's improved title and body (published only when every fact is
 * preserved), the moderation check, the first version, and the first ledger
 * entry. Failures here never take the story down: the review already stood.
 */
final class CommunityPublisher
{
    public function afterSubmit(NewsItem $post, array $data, ?array $pin): void
    {
        if (!config('services.community.enabled') || !$post->id) {
            return;
        }

        try {
            $this->record($post, $data, $pin);
        } catch (\Throwable $x) {
            Log::warning('Community publisher failed', ['news_item_id' => $post->id, 'error' => mb_substr($x->getMessage(), 0, 200)]);
        }
    }

    /**
     * A person published (or re-published) a reader's post by hand. The
     * community record follows: created if the automatic check had refused
     * it before one existed, marked published, and noted in the history.
     */
    public function adopt(NewsItem $post, ?int $adminId): void
    {
        if (!config('services.community.enabled')) {
            return;
        }

        $existing = DB::table('community_post_meta')->where('news_item_id', $post->id)->first();

        if (!$existing) {
            DB::table('community_post_meta')->insert([
                'news_item_id' => $post->id, 'original_title' => $post->title, 'original_body' => (string) $post->body,
                'published_title' => $post->title, 'published_body' => $post->body, 'moderation_status' => 'published',
                'selected_lat' => $post->latitude, 'selected_lng' => $post->longitude, 'place_name' => $post->location_label ?: $post->main_place_text,
                'location_source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('community_post_versions')->insert(['news_item_id' => $post->id, 'actor_type' => 'author', 'actor_id' => $post->contributor_id,
                'title' => $post->title, 'body' => (string) $post->body, 'reason' => 'as submitted', 'created_at' => now()]);
        } else {
            DB::table('community_post_meta')->where('news_item_id', $post->id)->update([
                'moderation_status' => 'published', 'trust_status' => $existing->trust_status === 'removed' ? 'unverified' : $existing->trust_status,
                'removed_at' => null, 'updated_at' => now(),
            ]);
        }

        CommunityTrust::history($post->id, 'moderation_status', $existing->moderation_status ?? null, 'published', 'moderator', $adminId, 'published by a person');

        if ($post->contributor_id) {
            CommunityTrust::ledger((int) $post->contributor_id, $post->id, 'published', 1, 0, 'report published by a person');
        }
    }

    private function record(NewsItem $post, array $data, ?array $pin): void
    {
        $original = ['title' => $data['title'], 'body' => $data['body']];
        $existing = DB::table('community_post_meta')->where('news_item_id', $post->id)->first();
        $distance = null;

        if ($pin && $pin['gps_lat'] !== null) {
            $distance = (int) round(self::metres($pin['gps_lat'], $pin['gps_lng'], $pin['lat'], $pin['lng']));
        }

        $meta = [
            'original_title' => $existing->original_title ?? $original['title'], 'original_body' => $existing->original_body ?? $original['body'],
            'published_title' => $post->title, 'published_body' => $post->body,
            'moderation_status' => $post->review_status === 'published' ? 'published' : ($post->review_status === 'rejected' ? 'rejected' : 'held'),
            'gps_lat_private' => $pin['gps_lat'] ?? null, 'gps_lng_private' => $pin['gps_lng'] ?? null, 'gps_accuracy_m' => $pin['accuracy'] ?? null,
            'selected_lat' => $pin['lat'] ?? null, 'selected_lng' => $pin['lng'] ?? null, 'pin_was_adjusted' => $pin['adjusted'] ?? false,
            'pin_adjustment_m' => $distance, 'location_source' => $pin['source'] ?? null, 'place_name' => $pin['label'] ?? ($data['place'] ?? null),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('community_post_meta')->where('news_item_id', $post->id)->update($meta);
        } else {
            DB::table('community_post_meta')->insert($meta + ['news_item_id' => $post->id, 'created_at' => now()]);
            DB::table('community_post_versions')->insert(['news_item_id' => $post->id, 'actor_type' => 'author', 'actor_id' => $post->contributor_id,
                'title' => $original['title'], 'body' => $original['body'], 'reason' => 'as submitted', 'created_at' => now()]);
        }

        if ($post->review_status !== 'published') {
            return;   // held or rejected by the review: nothing to improve yet
        }

        // the editor's pass
        // The text pass, now (about 1.5 s). The photo, if any, is reviewed a
        // few seconds behind by ReviewCommunityPhoto in the queue - the way
        // Facebook does it: fingerprints at upload, publish, then the model,
        // acting only if the photo fails. No photo, no vision call. Owner, 3 Sep.
        $result = DeepSeekCommunityModerationProvider::make()->review([
            'title' => $original['title'], 'body' => $original['body'], 'place' => $post->main_place_text, 'language' => $post->source_language,
            'trigger' => $existing ? 'edit' : 'submission', 'context' => null, 'image_path' => null,
        ]);

        DB::table('community_moderation_checks')->insert([
            'news_item_id' => $post->id, 'trigger_type' => $existing ? 'edit' : 'submission', 'provider' => DeepSeekCommunityModerationProvider::make()->providerName(), 'model' => $result['model'],
            'schema_version' => $result['schema_version'], 'decision' => $result['decision'], 'scores_json' => json_encode($result['scores']),
            'flags_json' => json_encode($result['flags']), 'added_claims_json' => json_encode($result['added_claims']),
            'public_reason' => $result['user_message'], 'response_redacted' => $result['raw'], 'latency_ms' => $result['latency_ms'], 'completed_at' => now(), 'created_at' => now(),
        ]);

        $update = [
            'breaking' => $result['breaking'], 'newsworthiness' => $result['scores']['newsworthiness'] ?? null, 'quality' => $result['scores']['quality'] ?? null,
            'safety' => $result['scores']['safety'] ?? null, 'location_confidence' => $result['scores']['location_confidence'] ?? null,
            'location_type' => $result['location_type'], 'seo_eligibility' => $result['breaking'] ? 'news' : 'normal',
            'expires_at' => $result['breaking'] ? now()->addDays(3) : null, 'updated_at' => now(),
        ];

        // the improved text is published only when every fact survived
        if ($result['decision'] === 'publish' && $result['facts_preserved'] && $result['improved_title']) {
            $newTitle = $result['improved_title'];
            $newBody  = $result['improved_body'] ?: $post->body;
            DB::table('news_items')->where('id', $post->id)->update(['title' => $newTitle, 'body' => $newBody, 'updated_at' => now()]);
            DB::table('feed_ready_items')->where('news_item_id', $post->id)->update(['title' => $newTitle, 'updated_at' => now()]);
            DB::table('community_post_versions')->insert(['news_item_id' => $post->id, 'actor_type' => 'ai', 'actor_id' => null,
                'title' => $newTitle, 'body' => $newBody, 'reason' => 'edited for clarity; facts preserved', 'created_at' => now()]);
            $update['published_title'] = $newTitle;
            $update['published_body']  = $newBody;
            $post->title = $newTitle;
            $post->body  = $newBody;
        } elseif ($result['decision'] === 'reject' && ($result['scores']['safety'] ?? 100) < 40) {
            // the existing review let it through but the editor sees a safety problem: hold for a person, never publish
            $update['moderation_status'] = 'held';
            DB::table('news_items')->where('id', $post->id)->update(['status' => 'held', 'review_status' => 'pending_review', 'review_reason' => mb_substr((string) ($result['user_message'] ?? 'held by the editor for a safety check'), 0, 300), 'updated_at' => now()]);
            $post->status = 'held';
            $post->review_status = 'pending_review';
        }

        DB::table('community_post_meta')->where('news_item_id', $post->id)->update($update);

        if ($post->contributor_id && $post->status === 'active') {
            CommunityTrust::ledger((int) $post->contributor_id, $post->id, 'published', 1, 0, 'report published (provisional)');
            Notifications::onPublished(DB::table('news_items')->where('id', $post->id)->first());
            Badges::recalculate((int) $post->contributor_id);
        }

        // the photo, behind the post
        if ($post->image_path && $post->status === 'active') {
            \App\Jobs\ReviewCommunityPhoto::dispatch($post->id)->onQueue('default');
        }
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

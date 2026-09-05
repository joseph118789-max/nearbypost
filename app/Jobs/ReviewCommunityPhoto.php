<?php

namespace App\Jobs;

use App\Services\Community\CommunityTrust;
use App\Services\Community\DeepSeekCommunityModerationProvider;
use App\Services\Community\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * The photo review, a few seconds behind the published report. DeepSeek V4
 * vision (or OpenAI when configured) says whether the picture plausibly
 * shows what the text says and lists what is visible. A photo that does not
 * (relevance under 25) or that is a screenshot, stock/recycled, graphic or
 * shows a minor HOLDS the report for a person and tells the author why.
 * A report that has meanwhile been held or removed is left alone.
 */
class ReviewCommunityPhoto implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    public int $uniqueFor = 600;

    public function __construct(public int $newsItemId)
    {
    }

    public function uniqueId(): string
    {
        return 'photo:' . $this->newsItemId;
    }

    public function handle(): void
    {
        $item = DB::table('news_items')->where('id', $this->newsItemId)->first();
        $meta = DB::table('community_post_meta')->where('news_item_id', $this->newsItemId)->first();

        if (!$item || !$meta || !$item->image_path || !is_file(public_path($item->image_path))) {
            return;
        }

        $provider = DeepSeekCommunityModerationProvider::make();
        $result = $provider->review([
            'title' => $item->title, 'body' => (string) $item->body, 'place' => $item->location_label ?: $item->main_place_text, 'language' => $item->source_language,
            'trigger' => 'photo', 'image_path' => public_path($item->image_path),
            'context' => 'The text has already been checked and published. Judge the PHOTO: does it plausibly show what the text describes, and is it safe to show. Keep decision "publish" unless the photo itself is the problem.',
        ]);

        DB::table('community_moderation_checks')->insert([
            'news_item_id' => $this->newsItemId, 'trigger_type' => 'photo', 'provider' => $provider->providerName(), 'model' => $result['model'],
            'schema_version' => $result['schema_version'], 'decision' => $result['decision'], 'scores_json' => json_encode($result['scores']),
            'flags_json' => json_encode($result['flags']), 'facts_json' => json_encode(['visible' => $result['visible_facts'] ?? [], 'image_relevance' => $result['scores']['image_relevance'] ?? null]),
            'public_reason' => $result['user_message'], 'response_redacted' => $result['raw'], 'latency_ms' => $result['latency_ms'],
            'attempt' => $this->attempts(), 'completed_at' => now(), 'created_at' => now(),
        ]);

        if (empty($result['image_reviewed'])) {
            return;   // no vision available this time; the fingerprints at upload stand
        }

        DB::table('community_post_media')->where('news_item_id', $this->newsItemId)->update(['vision_reviewed_at' => now(), 'updated_at' => now()]);

        $flags = array_intersect($result['flags'], ['screenshot', 'stock_or_recycled', 'graphic', 'minor']);
        $relevance = $result['scores']['image_relevance'];
        $bad = ($relevance !== null && $relevance < 25) || $flags !== [] || in_array($result['decision'], ['reject', 'hold'], true);

        // only a report still live is held; anything a person or the editor has already acted on is theirs
        if (!$bad || $item->status !== 'active' || $meta->moderation_status !== 'published') {
            return;
        }

        $why = $result['user_message'] ?: ('The photo does not appear to show what the report describes' . ($flags ? ' (' . implode(', ', $flags) . ')' : '') . '. A person will look at it.');
        DB::table('community_post_meta')->where('news_item_id', $this->newsItemId)->update(['moderation_status' => 'held', 'updated_at' => now()]);
        DB::table('news_items')->where('id', $this->newsItemId)->update(['status' => 'held', 'review_status' => 'pending_review', 'review_reason' => mb_substr($why, 0, 300), 'updated_at' => now()]);
        DB::table('feed_ready_items')->where('news_item_id', $this->newsItemId)->update(['is_active' => false, 'updated_at' => now()]);
        CommunityTrust::history($this->newsItemId, 'moderation_status', 'published', 'held', 'ai', null, 'photo review: ' . $why);

        if ($item->contributor_id) {
            Notifications::send((int) $item->contributor_id, 'photo_held', 'Your report is on hold because of its photo', $why, url('/contribute'), $this->newsItemId);
        }
    }
}

<?php

namespace App\Jobs;

use App\Services\Community\CommunityTrust;
use App\Services\Community\DeepSeekCommunityModerationProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * The first meaningful report (or a threshold) sends the post back to the
 * editor with the complaint attached. The editor may keep it, mark it
 * disputed, hide it for a person, or remove it - and says why, in words the
 * author will read. Unique per post so a burst of reports is one review.
 */
class ReReviewCommunityPost implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 90;
    public int $uniqueFor = 300;

    public function __construct(public int $newsItemId, public string $reason, public ?string $explanation = null)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->newsItemId;
    }

    public function handle(): void
    {
        $item = DB::table('news_items')->where('id', $this->newsItemId)->first();
        $meta = DB::table('community_post_meta')->where('news_item_id', $this->newsItemId)->first();

        if (!$item || !$meta || $meta->trust_status === 'removed') {
            return;
        }

        $reports = DB::table('community_reports')->where('news_item_id', $this->newsItemId)->orderByDesc('id')->limit(5)->get();
        $context = "This report has been flagged by readers. Complaints:\n" . $reports->map(fn ($r) => "- {$r->reason}" . ($r->explanation ? ": {$r->explanation}" : ''))->implode("\n")
            . sprintf("\nConfirmations (weighted): %.1f. Disputes (weighted): %.1f. Original title: %s", $meta->saw_weight, $meta->wrong_weight, $meta->original_title)
            . "\nDecide: \"publish\" = keep as is; \"needs_revision\" = mark disputed and tell the author what to fix; \"hold\" = hide for a person to look at; \"reject\" = remove (only for spam, harassment, doxxing, graphic, clearly false).";

        $result = DeepSeekCommunityModerationProvider::make()->review([
            'title' => $item->title, 'body' => (string) $item->body, 'place' => $item->location_label ?: $item->main_place_text,
            'language' => $item->source_language, 'trigger' => 'report', 'context' => $context,
        ]);

        DB::table('community_moderation_checks')->insert([
            'news_item_id' => $this->newsItemId, 'trigger_type' => 'report', 'provider' => DeepSeekCommunityModerationProvider::make()->providerName(), 'model' => $result['model'],
            'schema_version' => $result['schema_version'], 'decision' => $result['decision'], 'scores_json' => json_encode($result['scores']),
            'flags_json' => json_encode($result['flags']), 'added_claims_json' => json_encode($result['added_claims']),
            'public_reason' => $result['user_message'], 'response_redacted' => $result['raw'], 'latency_ms' => $result['latency_ms'],
            'attempt' => $this->attempts(), 'completed_at' => now(), 'created_at' => now(),
        ]);

        DB::table('community_post_meta')->where('news_item_id', $this->newsItemId)->update(['last_review_at' => now()]);

        match ($result['decision']) {
            'needs_revision' => CommunityTrust::setStatus($this->newsItemId, 'disputed', 'ai', null, $result['user_message'] ?? 'readers disputed it and the editor agreed a detail needs fixing'),
            'hold'           => $this->hide($result['user_message'] ?? 'hidden for a person to look at after reader reports'),
            'reject'         => CommunityTrust::setStatus($this->newsItemId, 'removed', 'ai', null, $result['user_message'] ?? 'removed after reader reports'),
            default          => DB::table('community_reports')->where('news_item_id', $this->newsItemId)->where('status', 'open')->update(['status' => 'rejected', 'updated_at' => now()]),
        };
    }

    private function hide(string $why): void
    {
        DB::table('community_post_meta')->where('news_item_id', $this->newsItemId)->update(['moderation_status' => 'held', 'updated_at' => now()]);
        DB::table('news_items')->where('id', $this->newsItemId)->update(['status' => 'held', 'review_status' => 'pending_review', 'review_reason' => mb_substr($why, 0, 300), 'updated_at' => now()]);
        CommunityTrust::history($this->newsItemId, 'moderation_status', 'published', 'held', 'ai', null, $why);
    }
}

<?php

namespace App\Jobs;

use App\Models\NewsItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * ⚠️ LEGACY JOB — DO NOT USE AS AUTHORITATIVE D11 PIPELINE
 *
 * This job is DEPRECATED. It exists only as a reference implementation
 * and MUST NOT be used as the primary AI enrichment path.
 *
 * D11 AUTHORITATIVE PATH: app/Console/Commands/EnrichWithAi
 * (run via: php artisan ingest:enrich)
 *
 * Why this is legacy / non-authoritative:
 * - Uses a looser validation model with different enum contracts
 * - Writes directly to news_items without going through AiProcessingJob tracking
 * - Does not enforce the same prompt/pipeline versioning as EnrichWithAi
 * - Has different fallback behavior and coordinate validation
 *
 * If anything dispatches this job, it indicates a split-brain enrichment bug.
 * This guard logs a CRITICAL signal so it surfaces immediately in logs.
 */
class EnrichArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;
    public int $timeout = 180;

    public function __construct(private int $newsItemId) {}

    public function handle(): void
    {
        // ── FAIL-FAST GUARD ─────────────────────────────────────────────────
        // This job is legacy. Log a CRITICAL signal so it is visible in monitoring.
        // Do NOT silently run the old enrichment path — that would bypass D11 validation.
        Log::critical('EnrichArticleJob: BLOCKED — this legacy job is not the D11 authoritative path. '
            . 'Use EnrichWithAi (php artisan ingest:enrich) instead.', [
            'news_item_id' => $this->newsItemId,
            'hint' => 'Remove any dispatch(EnrichArticleJob::class) calls. '
                    . 'The authoritative path is app/Console/Commands/EnrichWithAi.',
        ]);

        // Mark the item as failed so the blocked state is visible, then abort.
        // Do NOT perform enrichment here — that would bypass the D11 validated path.
        NewsItem::where('id', $this->newsItemId)->update([
            'ai_status' => 'failed',
        ]);

        // Raise an exception so the job is marked as failed in queue tracking.
        throw new \RuntimeException(
            'EnrichArticleJob is legacy and blocked. '
            . 'Use EnrichWithAi (php artisan ingest:enrich) for D11 AI enrichment.'
        );
    }
}

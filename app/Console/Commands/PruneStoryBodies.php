<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drop the working copy of an article once it has done its work.
 *
 * A reader never sees the stored body: the public page carries a title, a
 * summary and a link to the publisher. The body exists so the classifier can
 * read it, so the deduplicator can find numbers in it, and so a person can
 * check an answer against it. After a few days none of those are happening,
 * and it is 21% of what a story costs to keep.
 *
 * The full model reply goes with it, and sooner would be no loss: nothing reads
 * raw_ai_output after the day it is written, and it is the single largest thing
 * we store per story - 4,698 bytes against the article's 1,837. Keeping a
 * debugging record longer than the article it explains would be the wrong way
 * round.
 *
 * THREE THINGS ARE NEVER PRUNED, and they are the point of the command rather
 * than exceptions to it:
 *
 *   Anything not yet classified. Obvious, but worth enforcing rather than
 *   assuming the backlog is always short - it was 1,741 stories this week.
 *
 *   Anything on the bench. Those stories are the yardstick: bench:run re-reads
 *   each article to score the model against the answers a person confirmed.
 *   Pruning them on a three-day clock would quietly destroy the measurement
 *   inside a week, which is exactly how the bench ended up with 51 rows
 *   pointing at stories that no longer existed.
 *
 *   Anything a person has corrected. A correction is a claim about what an
 *   article said, and the article is the evidence for it.
 */
class PruneStoryBodies extends Command
{
    protected $signature = 'ingest:prune
        {--body-days=3  : Keep the article body this many days after publication}
        {--raw-days=3   : Keep the raw model reply this many days}
        {--workings-days=30 : Keep the workings of the classifier this many days}
        {--dry-run      : Report what would go, delete nothing}';

    protected $description = 'Delete article bodies and raw model replies once nothing needs them';

    public function handle(): int
    {
        $dry      = (bool) $this->option('dry-run');
        $bodyDays = max(1, (int) $this->option('body-days'));
        $rawDays  = max(1, (int) $this->option('raw-days'));

        $this->info(sprintf(
            '%s bodies older than %d days, model replies older than %d days.',
            $dry ? 'Would prune' : 'Pruning',
            $bodyDays,
            $rawDays
        ));

        $bodyFreed = $this->pruneBodies($bodyDays, $dry);
        $rawFreed  = $this->pruneRawReplies($rawDays, $dry);
        $workFreed = $this->pruneWorkings((int) $this->option('workings-days'), $dry);

        $this->newLine();
        $this->info(sprintf(
            '%s %s (bodies %s, model replies %s, workings %s).',
            $dry ? 'Would reclaim' : 'Reclaimed',
            $this->human($bodyFreed + $rawFreed + $workFreed),
            $this->human($bodyFreed),
            $this->human($rawFreed),
            $this->human($workFreed)
        ));

        if (!$dry) {
            // Space freed inside a table is reused by that table, not returned
            // to the disk. Worth saying plainly so nobody watches df and
            // concludes the command did nothing.
            $this->line('  Space is released for reuse inside the tables, not back to the disk.');
        }

        Log::info('Story bodies pruned', [
            'body_bytes' => $bodyFreed, 'raw_bytes' => $rawFreed, 'dry' => $dry,
        ]);

        return 0;
    }

    /**
     * The article body, for stories that are finished with it.
     *
     * Keyed on published_at rather than created_at: the age that matters is the
     * story's, not ours. A story we fetched late is still old news.
     */
    private function pruneBodies(int $days, bool $dry): int
    {
        $rows = DB::table('extraction_jobs as e')
            ->join('news_items as n', 'n.id', '=', 'e.news_item_id')
            ->whereNotNull('e.extracted_text')
            ->where('e.extracted_text', '!=', '')
            ->where('n.published_at', '<', now()->subDays($days))
            // Only once the classifier has had its turn.
            ->whereIn('n.ai_status', ['success', 'discarded', 'duplicate', 'insufficient_content'])
            // ONLY GATHERED STORIES. What is pruned here is our working copy of
            // somebody else's article, and the publisher still has the original
            // at the URL we keep. A reader's own post has no original anywhere
            // else - we are the publisher - so it is never a copy and never
            // pruned. Their text lives in news_items.body and their photograph
            // in image_path, neither of which this command touches; the rule is
            // stated anyway so the intent survives a future refactor.
            ->where('n.origin', 'scraper')
            // The yardstick keeps its evidence.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('bench_items as b')->whereColumn('b.news_item_id', 'n.id'))
            // So does anything a person has argued with.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('corrections as c')->whereColumn('c.news_item_id', 'n.id'))
            ->get([
                'e.id',
                DB::raw('octet_length(e.extracted_text) as bytes'),
                DB::raw('length(e.extracted_text) as chars'),
                DB::raw('left(e.extracted_text, 280) as opening'),
            ]);

        $freed = (int) $rows->sum('bytes');

        $this->line(sprintf(
            '  article bodies    : %6d stories, %s',
            $rows->count(),
            $this->human($freed)
        ));

        if (!$dry && $rows->isNotEmpty()) {
            // In batches: a single IN () of tens of thousands of ids is how a
            // tidy-up job becomes an outage.
            foreach ($rows as $row) {
                DB::table('extraction_jobs')
                    ->where('id', $row->id)
                    // Status stays 'success' so extraction never retries this,
                    // and the method records why the text is gone.
                    //
                    // The length and first paragraph stay behind: they are what
                    // a later re-fetch is checked against, and without them a
                    // paywall stub returned under HTTP 200 would be
                    // indistinguishable from the article.
                    ->update([
                        'extracted_text'    => null,
                        'original_length'   => (int) $row->chars,
                        'body_opening'      => $row->opening,
                        'extraction_method' => DB::raw("coalesce(extraction_method,'') || '+pruned'"),
                        'updated_at'        => now(),
                    ]);
            }
        }

        return $freed;
    }

    /** The full model reply, which nothing reads after the day it lands. */
    private function pruneRawReplies(int $days, bool $dry): int
    {
        $rows = DB::table('ai_processing_jobs as j')
            ->whereNotNull('j.raw_ai_output')
            ->where('j.raw_ai_output', '!=', '')
            ->where('j.created_at', '<', now()->subDays($days))
            ->get(['j.id', DB::raw('octet_length(j.raw_ai_output) as bytes')]);

        $freed = (int) $rows->sum('bytes');

        $this->line(sprintf(
            '  raw model replies : %6d jobs,    %s',
            $rows->count(),
            $this->human($freed)
        ));

        if (!$dry && $rows->isNotEmpty()) {
            foreach ($rows->pluck('id')->chunk(500) as $chunk) {
                DB::table('ai_processing_jobs')
                    ->whereIn('id', $chunk->all())
                    ->update(['raw_ai_output' => null, 'updated_at' => now()]);
            }
        }

        return $freed;
    }

    /**
     * The classifier's workings, once nothing reads them.
     *
     * Kept far longer than the body because they are what a person looks at
     * when an answer seems wrong - the roles it gave each place, the summary it
     * validated. After a month a story is not being re-judged and not being
     * argued about, and the reader has never seen any of this.
     *
     * The same three exemptions as the body: contributed stories, the bench,
     * and anything a person has corrected. A corrected story's workings are the
     * evidence for the correction.
     */
    private function pruneWorkings(int $days, bool $dry): int
    {
        $cutoff = now()->subDays($days);
        $freed = 0;

        $keep = fn ($q) => $q
            ->where('published_at', '<', $cutoff)
            ->where('origin', 'scraper')
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))
                ->from('bench_items as b')->whereColumn('b.news_item_id', 'news_items.id'))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))
                ->from('corrections as c')->whereColumn('c.news_item_id', 'news_items.id'));

        // The place reasoning. Only ingest:multipoint reads it, and that has
        // run on anything this old.
        $roles = DB::table('news_items')
            ->whereNotNull('place_roles')
            ->where(fn ($q) => $keep($q))
            ->sum(DB::raw('octet_length(place_roles::text)'));

        if (!$dry && $roles > 0) {
            DB::table('news_items')->whereNotNull('place_roles')
                ->where(fn ($q) => $keep($q))
                ->update(['place_roles' => null]);
        }

        $freed += (int) $roles;

        // The extraction's own summary: a fallback for a classifier that is
        // not going to run again on a month-old story.
        $extracted = DB::table('extraction_jobs as e')
            ->join('news_items as n', 'n.id', '=', 'e.news_item_id')
            ->whereNotNull('e.extracted_summary')
            ->where('n.published_at', '<', $cutoff)
            ->where('n.origin', 'scraper')
            ->sum(DB::raw('octet_length(e.extracted_summary)'));

        if (!$dry && $extracted > 0) {
            DB::statement(
                'update extraction_jobs e set extracted_summary = null
                 from news_items n
                 where n.id = e.news_item_id and e.extracted_summary is not null
                   and n.published_at < ? and n.origin = \'scraper\'',
                [$cutoff]
            );
        }

        $freed += (int) $extracted;

        // The validated copy of the summary. The story keeps its own.
        $validated = DB::table('ai_processing_jobs as j')
            ->join('news_items as n', 'n.id', '=', 'j.news_item_id')
            ->whereNotNull('j.validated_summary')
            ->where('n.published_at', '<', $cutoff)
            ->where('n.origin', 'scraper')
            ->sum(DB::raw('octet_length(j.validated_summary)'));

        if (!$dry && $validated > 0) {
            DB::statement(
                'update ai_processing_jobs j set validated_summary = null, validation_notes = null
                 from news_items n
                 where n.id = j.news_item_id and j.validated_summary is not null
                   and n.published_at < ? and n.origin = \'scraper\'',
                [$cutoff]
            );
        }

        $freed += (int) $validated;

        $this->line(sprintf(
            '  workings          : place reasoning %s, extract summaries %s, validated %s',
            $this->human((int) $roles),
            $this->human((int) $extracted),
            $this->human((int) $validated)
        ));

        return $freed;
    }

    private function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}

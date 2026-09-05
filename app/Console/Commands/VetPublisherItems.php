<?php

namespace App\Console\Commands;

use App\Services\Ingest\PublisherVetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read what connected publishers have sent, before any of it is served.
 *
 * ⛔ Marking arrivals happens FIRST and on every run, even when nothing is
 * vetted afterwards. The gate has to close when a story arrives, not when a
 * model gets round to it - otherwise an item sits servable for as long as the
 * queue is behind, which is exactly when you would least want it to.
 */
class VetPublisherItems extends Command
{
    protected $signature = 'publisher:vet {--limit=40} {--quiet-ok}';
    protected $description = 'Read each item from a connected publisher and decide whether it may be shown';

    public function handle(): int
    {
        $marked = PublisherVetting::markArrivals();

        $waiting = DB::table('news_items')
            ->where('publisher_review_status', 'pending')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'title', 'source']);

        if ($waiting->isEmpty()) {
            if (!$this->option('quiet-ok')) {
                $this->info($marked > 0
                    ? "Marked {$marked} new arrival(s); nothing ready to read yet."
                    : 'Nothing waiting.');
            }

            return self::SUCCESS;
        }

        $tally = ['passed' => 0, 'held' => 0, 'rejected' => 0];

        foreach ($waiting as $item) {
            $r = PublisherVetting::vet((int) $item->id);
            $tally[$r['status']] = ($tally[$r['status']] ?? 0) + 1;

            $this->line(sprintf('  %-8s %-22s %s%s',
                $r['status'],
                mb_substr((string) $item->source, 0, 22),
                mb_substr((string) $item->title, 0, 46),
                $r['status'] === 'passed' ? '' : '  -- ' . mb_substr($r['why'], 0, 50)));
        }

        $this->info(sprintf('%d passed, %d held, %d rejected.',
            $tally['passed'], $tally['held'], $tally['rejected']));

        return self::SUCCESS;
    }
}

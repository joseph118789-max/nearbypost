<?php

namespace App\Console\Commands;

use App\Services\Ingest\SourceRequests;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Look at the sites people have offered us.
 *
 * ⛔ NOT DONE IN THE WEB REQUEST. Probing a site means six HTTP calls to
 * somebody else's server; a form that waits on those is a form that times out
 * on exactly the slow sites most worth checking. The submission lands, this
 * catches up within the quarter hour, and a person sees a finished row.
 */
class AssessSourceRequests extends Command
{
    protected $signature = 'sources:assess-requests {--limit=10}';
    protected $description = 'Probe sites offered through the public form and note what was found';

    public function handle(): int
    {
        $ids = DB::table('source_requests')
            ->whereIn('status', ['new', 'probing'])
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            try {
                SourceRequests::assess((int) $id);
                $row = DB::table('source_requests')->where('id', $id)
                    ->first(['website_url', 'found_kind', 'items_per_week', 'recommended_cadence', 'ai_decision']);

                $this->line(sprintf('  %-44s %-11s %-7s %-7s %s',
                    mb_substr((string) $row->website_url, 0, 44),
                    $row->found_kind ?: 'nothing',
                    $row->items_per_week === null ? '-' : $row->items_per_week . '/wk',
                    $row->recommended_cadence ?: '-',
                    $row->ai_decision ?: '-'));
            } catch (\Throwable $e) {
                $this->warn('  ' . $id . ': ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}

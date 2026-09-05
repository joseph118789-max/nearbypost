<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Look less often at sources that have stopped saying anything.
 *
 * ⛔ A SOURCE THAT PUBLISHES NOTHING IS NOT A BROKEN SOURCE, AND THAT IS WHY
 * NOTHING CAUGHT IT. `consecutive_failures` counts servers that would not
 * answer; a site that answers perfectly and has posted nothing since March
 * leaves it at zero forever. So it was fetched daily, indefinitely, for
 * nothing - which costs a request every time and hides real sources in the
 * logs behind ones that never move.
 *
 * The rule the spec suggested and the owner's default agree: three cycles with
 * nothing new is enough to look less often. Daily becomes weekly, weekly is
 * parked. Nothing is deleted and nothing is switched off - a parked source is
 * still there, still readable on its page, and one edit from coming back.
 */
class DecayQuietSources extends Command
{
    protected $signature = 'sources:decay {--after=3 : Empty fetches in a row before easing off} {--dry-run}';
    protected $description = 'Ease off sources that have brought back nothing for several runs';

    public function handle(): int
    {
        $after = max(2, (int) $this->option('after'));
        $dry   = (bool) $this->option('dry-run');

        $quiet = DB::table('sources')
            ->where('is_active', true)
            ->where('consecutive_empty', '>=', $after)
            ->whereIn('priority_tier', ['primary', 'secondary', 'weekly'])
            ->orderByDesc('consecutive_empty')
            ->get(['id', 'name', 'priority_tier', 'consecutive_empty', 'last_fetched_at']);

        if ($quiet->isEmpty()) {
            if (!$this->option('quiet')) {
                $this->info('Every source has said something recently.');
            }

            return self::SUCCESS;
        }

        foreach ($quiet as $s) {
            // primary and secondary are the frequent tiers; weekly is already
            // the gentle one, so the only step left from there is to park it.
            $to = $s->priority_tier === 'weekly' ? 'parked' : 'weekly';

            $this->line(sprintf('  %-34s %d empty runs   %s -> %s%s',
                mb_substr($s->name, 0, 34), $s->consecutive_empty,
                $s->priority_tier, $to, $dry ? '  (dry run)' : ''));

            if ($dry) {
                continue;
            }

            DB::table('sources')->where('id', $s->id)->update([
                'priority_tier'     => $to,
                'downgraded_at'     => now(),

                // ⛔ Reset, or it decays again on its very next empty run and
                // walks from primary to parked in three fetches.
                'consecutive_empty' => 0,
                'workaround_note'   => trim((string) DB::table('sources')->where('id', $s->id)->value('workaround_note')
                    . ' Eased to ' . $to . ' on ' . now()->addHours(8)->toDateString()
                    . ' after ' . $s->consecutive_empty . ' fetches with nothing new.'),
                'notes_updated_at'  => now(),
                'updated_at'        => now(),
            ]);

            DB::table('audit_events')->insert([
                'event' => 'source.eased_off', 'subject_type' => 'source', 'subject_id' => $s->id,
                'after' => json_encode(['from' => $s->priority_tier, 'to' => $to,
                                        'empty_runs' => $s->consecutive_empty]),
                'reason' => 'Nothing new for ' . $s->consecutive_empty . ' fetches.',
                'created_at' => now(),
            ]);
        }

        $this->info(sprintf('%s %d source%s.', $dry ? 'Would ease off' : 'Eased off',
            $quiet->count(), $quiet->count() === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}

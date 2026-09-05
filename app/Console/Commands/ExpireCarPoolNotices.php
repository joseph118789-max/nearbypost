<?php

namespace App\Console\Commands;

use App\Services\Marketplace\CarPool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Take down journeys that have been and gone, then forget where they started.
 *
 * Spec 28: "Car-pool notices should normally be removed/noindexed after
 * departure because they are temporary and privacy-sensitive."
 *
 * TWO STAGES, BECAUSE "REMOVED" MEANS TWO DIFFERENT THINGS HERE.
 *
 * The first is about usefulness: six hours after departure a lift is of no use
 * to anybody, and CarPool::publish derives that expiry from the journey rather
 * than letting anyone choose it. Sweeping it flips the notice out of every
 * served list and out of matching.
 *
 * The second is the one that actually matters, and expiring alone does not do
 * it. An expired notice still holds `origin_private_lat/lng` - the exact point
 * somebody set off from, which for most people is their front door, stamped
 * with the time they leave. One row is a journey. A year of rows is a pattern
 * of life: which mornings they are home, when they travel, when the house is
 * empty. That is a far more sensitive record than the listing it came from,
 * and nothing was ever going to need it after the ride.
 *
 * So the private points are erased once the notice is well past. The public
 * cell centres stay - they are already coarse (about 1.1 km, see
 * PublicLocation) and they keep the notice legible to a moderator handling a
 * later safety report, which is the one reason to keep anything at all.
 *
 * ⛔ SCHEDULED EVEN THOUGH THE FEATURE IS OFF EVERYWHERE. carpool_enabled is
 * off in every country pending transport-law review, so today this sweeps
 * nothing. It is scheduled anyway, because the alternative is remembering to
 * add it on the day the flag goes on - and a retention job that arrives after
 * the data does is how a privacy-sensitive record gets left standing.
 */
class ExpireCarPoolNotices extends Command
{
    protected $signature = 'carpool:expire
        {--forget-after=7 : Days after expiry to erase the exact start and end points}
        {--dry-run        : Report what would change, change nothing}';

    protected $description = 'Expire departed car-pool notices and erase their exact points';

    public function handle(): int
    {
        $dry  = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('forget-after'));

        // Stage one: out of every list, out of matching.
        $due = DB::table('carpool_notices')
            ->where('publication_status', 'published')
            ->where('expires_at', '<=', now())
            ->count();

        $expired = $dry ? $due : CarPool::expire();

        // Stage two: forget where they set off from. Anything no longer
        // published and well past its expiry, whose points are still there.
        $stale = DB::table('carpool_notices')
            ->where('publication_status', '!=', 'published')
            ->where('expires_at', '<=', now()->subDays($days))
            ->where(function ($q) {
                $q->whereNotNull('origin_private_lat')->orWhereNotNull('destination_private_lat');
            });

        $toForget = (clone $stale)->count();

        $forgotten = $dry ? $toForget : $stale->update([
            'origin_private_lat'      => null,
            'origin_private_lng'      => null,
            'destination_private_lat' => null,
            'destination_private_lng' => null,
            'updated_at'              => now(),
        ]);

        $this->line(sprintf('%s %d departed %s, erased the exact points of %d older than %d days.',
            $dry ? 'Would expire' : 'Expired',
            $expired,
            $expired === 1 ? 'notice' : 'notices',
            $forgotten,
            $days));

        return self::SUCCESS;
    }
}

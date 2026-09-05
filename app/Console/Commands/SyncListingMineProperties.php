<?php

namespace App\Console\Commands;

use App\Services\Marketplace\PropertyMirror;
use App\Services\Marketplace\PropertyRefused;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Pull property listings from ListingMine. Spec 27.
 *
 * ⛔ THE ENDPOINT DOES NOT EXIST YET, AND THIS COMMAND SAYS SO PLAINLY RATHER
 * THAN FAILING IN A WAY THAT LOOKS LIKE A QUIET NIGHT.
 *
 * Everything downstream of the payload is built and tested - the mirror, the
 * idempotency, the stale-payload guard, the source-of-truth trigger. What is
 * missing is one URL on the ListingMine side. Until it exists, `--file=` reads
 * the same shape from disk, so the whole path can be exercised by whoever is
 * writing that endpoint before it is switched on.
 *
 * ⛔ Configuration goes through config(), never env(). On this project a gate
 * written as `env(...)` silently disabled the entire duplicate-story checker
 * for a day and a half, because env() returns null once the config is cached -
 * and it is always cached in production. See DedupeStories.
 */
class SyncListingMineProperties extends Command
{
    protected $signature = 'property:sync
        {--file=      : Read the payload from a local JSON file instead of the API}
        {--pages=20   : Most pages to walk in one run}
        {--dry-run    : Report what would change, write nothing}';

    protected $description = 'Mirror property listings from ListingMine (ListingMine stays the source of truth)';

    public function handle(): int
    {
        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0,
                  'ignored_stale' => 0, 'refused' => 0];

        $batches = $this->option('file')
            ? $this->fromFile((string) $this->option('file'))
            : $this->fromApi((int) $this->option('pages'));

        if ($batches === null) {
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        foreach ($batches as $property) {
            try {
                $tally[$dry ? 'unchanged' : PropertyMirror::sync($property)]++;
            } catch (PropertyRefused $e) {
                $tally['refused']++;
                $this->warn('  refused ' . ($property['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        $this->line(sprintf(
            '%s %d new, %d changed, %d unchanged, %d stale ignored, %d refused.',
            $dry ? 'Would sync:' : 'Synced:',
            $tally['created'], $tally['updated'], $tally['unchanged'],
            $tally['ignored_stale'], $tally['refused']
        ));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>>|null */
    private function fromFile(string $path): ?array
    {
        if (!is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            $this->error('That file is not JSON.');

            return null;
        }

        return $data['properties'] ?? $data;
    }

    /** @return list<array<string, mixed>>|null */
    private function fromApi(int $maxPages): ?array
    {
        $base  = (string) config('services.listingmine.property_feed_url', '');
        $token = (string) config('services.listingmine.token', '');

        if ($base === '') {
            // ⛔ Not an error to be retried by cron every hour in silence. The
            // thing that is missing is a decision, and it belongs to a person.
            $this->warn('No ListingMine property feed is configured, so there is nothing to pull.');
            $this->line('  Set LISTINGMINE_PROPERTY_FEED_URL (and LISTINGMINE_TOKEN) once ListingMine exposes it,');
            $this->line('  then run: php artisan config:cache');
            $this->line('  Until then: php artisan property:sync --file=sample.json exercises the same path.');

            return null;
        }

        $out  = [];
        $page = 1;

        while ($page <= $maxPages) {
            $response = Http::withToken($token)->timeout(30)
                ->get($base, ['page' => $page, 'per_page' => 100]);

            if (!$response->successful()) {
                $this->error("ListingMine answered HTTP {$response->status()} on page {$page}.");

                // Whatever came back before the failure is still good; a
                // partial pull is not a corrupt one, because every property is
                // upserted on its own key.
                break;
            }

            $batch = (array) $response->json('properties', []);

            if ($batch === []) {
                break;
            }

            $out = array_merge($out, $batch);
            $page++;
        }

        return $out;
    }
}

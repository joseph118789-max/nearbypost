<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\LocationAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Resolve main_place_text into canonical_place_name.
 *
 * Manual V6 §11 describes alias enrichment as an accelerator: it exists to
 * "improve geocoding accuracy" and "increase cache hit rate". The original
 * implementation instead made it a gate - an unmatched place produced a NULL
 * canonical_place_name, and the geocoder only ever selected matched rows. With
 * 42 aliases covering a whole country, 404 of 413 placed articles in a typical
 * week were dropped before they could be geocoded.
 *
 * Unmatched place text is now passed through unchanged (status 'passthrough')
 * so the geocoder can still resolve it. Aliases remain valuable - they fold
 * "PJ" and "George Town, Penang" onto one canonical name, which is what makes
 * the geocode cache effective - but they no longer decide what gets geocoded.
 */
class EnrichLocationAlias extends Command
{
    protected $signature = 'ingest:enrich-alias
        {--news_item_id= : Process a specific news item only}
        {--limit=50 : Max items per run}
        {--recheck : Also revisit rows already resolved, to pick up new aliases}
        {--seed : Seed the alias table with Malaysian places first}';

    protected $description = 'Resolve main_place_text into canonical_place_name via the location_aliases table';

    public function handle(): int
    {
        if ($this->option('seed')) {
            $this->seedAliases();
        }

        $query = NewsItem::query();

        if ($newsItemId = $this->option('news_item_id')) {
            $query->where('id', $newsItemId);
        } else {
            $query->whereNotNull('main_place_text')
                  ->where('main_place_text', '<>', '');

            if (!$this->option('recheck')) {
                // Rows still awaiting resolution, plus legacy 'unmatched' rows from
                // before passthrough existed (those have no canonical_place_name).
                $query->where(function ($q) {
                    $q->whereNull('alias_match_status')
                      ->orWhere('alias_match_status', 'unmatched')
                      ->orWhereNull('canonical_place_name');
                });
            }
        }

        $limit = max(1, (int) $this->option('limit'));
        // Newest first: a stage that cannot clear its backlog should
        // spend its limit on today's news, not on the same old stories
        // that have failed every run for months.
        $items = $query->orderByDesc('published_at')->limit($limit)->get();

        $this->info("Alias enrichment: processing {$items->count()} items.");

        $counts = ['matched' => 0, 'passthrough' => 0, 'empty' => 0];

        foreach ($items as $item) {
            $status = $this->resolveItem($item);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $this->info("Done. matched={$counts['matched']} passthrough={$counts['passthrough']} empty={$counts['empty']}");

        return 0;
    }

    private function resolveItem(NewsItem $item): string
    {
        $rawPlace = trim($item->main_place_text ?? '');

        $result     = LocationAlias::resolve($rawPlace);
        $normalized = LocationAlias::normalize($rawPlace);

        // An alias match on a trailing segment coarsens the location instead of
        // normalising it. "Desa ParkCity, Kuala Lumpur" matched only on "Kuala
        // Lumpur", which pinned the story 8 km from where it happened and hid
        // it from anyone searching Desa ParkCity. The alias is preferred only
        // when it matched the whole place text - which is what turns "PJ" into
        // "Petaling Jaya" - and otherwise the specific text is kept for the
        // geocoder, which resolves it precisely.
        if ($result['status'] === 'matched'
            && $normalized !== ''
            && mb_strtolower((string) $result['matched_on']) !== mb_strtolower($normalized)) {
            $result['status']     = 'passthrough';
            $result['canonical']  = $normalized;
            $result['alias_type'] = null;
        }

        // Unmatched but non-empty place text still deserves a geocode attempt.
        if ($result['status'] === 'unmatched' && $normalized !== '') {
            $result['status']    = 'passthrough';
            $result['canonical'] = $normalized;
        }

        $item->update([
            'canonical_place_name' => $result['canonical'],
            'alias_match_status'   => $result['status'],
            'alias_match_type'     => $result['alias_type'],
            'alias_matched_at'     => $result['status'] !== 'empty' ? now() : null,
        ]);

        $label     = strtoupper($result['status']);
        $canonical = $result['canonical'] ?? '-';
        $this->line("  {$label} {$item->id} | '{$rawPlace}' -> '{$canonical}'");

        Log::info('Alias enrichment', [
            'news_item_id' => $item->id,
            'raw_place'    => $rawPlace,
            'status'       => $result['status'],
            'canonical'    => $result['canonical'],
            'alias_type'   => $result['alias_type'],
        ]);

        return $result['status'];
    }

    private function seedAliases(): void
    {
        $count = 0;

        foreach (LocationAlias::seedMalaysianPlaces() as $alias) {
            LocationAlias::updateOrCreate(
                ['alias_text' => $alias[0]],
                [
                    'canonical_name' => $alias[1],
                    'alias_type'     => $alias[2],
                    'is_active'      => true,
                ]
            );
            $count++;
        }

        $this->info("Seeded {$count} Malaysian location aliases.");
    }
}

<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\LocationAlias;
use App\Models\AiProcessingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnrichLocationAlias extends Command
{
    protected $signature = 'ingest:enrich-alias
        {--news_item_id= : Process a specific news item only}
        {--seed : Seed the alias table with Malaysian places first}';

    protected $description = 'Resolve main_place_text → canonical_name via location_aliases table';

    public function handle(): int
    {
        if ($this->option('seed')) {
            $this->seedAliases();
        }

        $query = NewsItem::query();

        if ($newsItemId = $this->option('news_item_id')) {
            $query->where('id', $newsItemId);
        } else {
            // Only process items with a main_place_text that haven't been alias-resolved yet
            $query->whereNotNull('main_place_text')
                  ->where(function ($q) {
                      $q->whereNull('alias_match_status')
                        ->orWhere('alias_match_status', 'unmatched');
                  });
        }

        $items = $query->limit(50)->get();
        $this->info("Alias enrichment: processing {$items->count()} items.");

        foreach ($items as $item) {
            $this->resolveItem($item);
        }

        $this->info('Done.');
        return 0;
    }

    private function resolveItem(NewsItem $item): void
    {
        $rawPlace = trim($item->main_place_text ?? '');

        $result = LocationAlias::resolve($rawPlace);

        $item->update([
            'canonical_place_name' => $result['canonical'],
            'alias_match_status'   => $result['status'],
            'alias_match_type'    => $result['alias_type'],
            'alias_matched_at'    => $result['status'] !== 'empty' ? now() : null,
        ]);

        $label = strtoupper($result['status']);
        $canonical = $result['canonical'] ?? '—';
        $this->line("  {$label} {$item->id} | '{$rawPlace}' → '{$canonical}'");

        Log::info('Alias enrichment', [
            'news_item_id'       => $item->id,
            'raw_place'          => $rawPlace,
            'status'             => $result['status'],
            'canonical'          => $result['canonical'],
            'alias_type'         => $result['alias_type'],
        ]);
    }

    private function seedAliases(): void
    {
        $count = 0;
        foreach (LocationAlias::seedMalaysianPlaces() as $alias) {
            LocationAlias::updateOrCreate(
                ['alias_text' => $alias[0]],
                [
                    'canonical_name' => $alias[1],
                    'alias_type'    => $alias[2],
                    'is_active'     => true,
                ]
            );
            $count++;
        }
        $this->info("Seeded {$count} Malaysian location aliases.");
    }
}

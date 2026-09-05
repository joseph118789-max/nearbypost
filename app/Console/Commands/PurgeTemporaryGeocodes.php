<?php

namespace App\Console\Commands;

use App\Services\GeocodingService;
use Illuminate\Console\Command;

/**
 * Let go of cached answers we are not allowed to keep for good.
 *
 * HERE's Base plan permits temporary caching only ("Permanent Geocoding is
 * not included"). Answers that could be matched to our own OpenStreetMap
 * data were stored as OSM's coordinates and are ours; the rest carry
 * provider 'here' and are dropped after 30 days. Daily at 05:20.
 */
class PurgeTemporaryGeocodes extends Command
{
    protected $signature = 'geocode:purge-temporary';

    protected $description = 'Drop geocode_cache rows from providers whose terms allow temporary caching only (HERE, 30 days)';

    public function handle(): int
    {
        $n = GeocodingService::purgeTemporary();
        $this->info("dropped {$n} temporary HERE row(s) older than 30 days");

        return self::SUCCESS;
    }
}

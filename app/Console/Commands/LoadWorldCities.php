<?php

namespace App\Console\Commands;

use App\Services\Geo\GazetteerSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load GeoNames cities15000 into world_cities.
 *
 *   php artisan cities:load                     downloads and loads everything
 *   php artisan cities:load --file=/tmp/x.txt   loads a file already on disk
 *   php artisan cities:load --min=500000        only the big ones
 *
 * Re-running is safe: rows are matched on geoname_id and updated in place, so a
 * refresh months from now corrects populations without duplicating anything.
 */
class LoadWorldCities extends Command
{
    protected $signature = 'cities:load {--file=} {--min=0} {--url=https://download.geonames.org/export/dump/cities15000.zip}';

    protected $description = 'Load world city populations from GeoNames (cities15000)';

    public function handle(): int
    {
        $file = (string) $this->option('file');

        if ($file === '') {
            $file = (string) $this->download((string) $this->option('url'));
        }

        if ($file === '' || !is_readable($file)) {
            $this->error('Cannot read the city list.');

            return self::FAILURE;
        }

        $min    = (int) $this->option('min');
        $handle = fopen($file, 'r');
        $batch  = [];
        $seen   = 0;
        $kept   = 0;

        while (($line = fgets($handle)) !== false) {
            $c = explode("\t", rtrim($line, "\r\n"));

            // geonameid, name, asciiname, alternatenames, lat, lng, feature
            // class, feature code, country, cc2, admin1 ... population is the
            // fifteenth column.
            if (count($c) < 15) {
                continue;
            }

            $seen++;
            $population = (int) $c[14];

            if ($population < $min || $c[8] === '') {
                continue;
            }

            $batch[] = [
                'geoname_id'   => (int) $c[0],
                'name'         => mb_substr($c[1], 0, 200),
                'ascii_name'   => mb_substr($c[2], 0, 200),
                'name_key'     => GazetteerSearch::key($c[1]),
                'country_code' => strtoupper(mb_substr($c[8], 0, 2)),
                'admin1_code'  => mb_substr($c[10], 0, 20),
                'population'   => $population,
                'lat'          => (float) $c[4],
                'lng'          => (float) $c[5],
                'feature_code' => mb_substr($c[7], 0, 10),
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
            $kept++;

            if (count($batch) >= 1000) {
                $this->flush($batch);
                $batch = [];
                $this->output->write('.');
            }
        }

        fclose($handle);

        if ($batch !== []) {
            $this->flush($batch);
        }

        $this->newLine();
        $this->info(sprintf('Read %s rows, stored %s.', number_format($seen), number_format($kept)));

        $over      = DB::table('world_cities')->where('population', '>', 500000)->count();
        $countries = DB::table('world_cities')->where('population', '>', 500000)->distinct()->count('country_code');

        $this->line(sprintf('  above 500,000: %s cities across %d countries', number_format($over), $countries));

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $batch */
    private function flush(array $batch): void
    {
        DB::table('world_cities')->upsert($batch, ['geoname_id'],
            ['name', 'ascii_name', 'name_key', 'country_code', 'admin1_code', 'population', 'lat', 'lng', 'feature_code', 'updated_at']);
    }

    private function download(string $url): ?string
    {
        $zip = sys_get_temp_dir() . '/cities15000.zip';
        $this->line("Downloading {$url} ...");

        $body = @file_get_contents($url);

        if ($body === false || strlen($body) < 100000) {
            return null;
        }

        file_put_contents($zip, $body);

        $archive = new \ZipArchive();

        if ($archive->open($zip) !== true) {
            return null;
        }

        $archive->extractTo(sys_get_temp_dir());
        $archive->close();

        $txt = sys_get_temp_dir() . '/cities15000.txt';

        return is_readable($txt) ? $txt : null;
    }
}

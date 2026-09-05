<?php

namespace App\Services\Geo;

/**
 * Which Nominatim answers for which country.
 *
 * One container per region, each holding one Geofabrik extract and its own
 * updates: "nominatim" (Malaysia, Singapore, Brunei), "nominatim-au",
 * "nominatim-gb". A query for a country none of them covers goes to the
 * public instance, throttled. Before this, a question about Florida went to
 * the Malaysian container, which answered 200 with an empty list - taken as
 * "no such place" rather than "not my country".
 */
final class NominatimRouter
{
    public const PUBLIC_URL = 'https://nominatim.openstreetmap.org';

    /**
     * @return array{url: string, local: bool}  local = our own container (no throttle; fall back to public when down)
     */
    public static function baseFor(?string $countryCodes): array
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $countryCodes)))));

        // no country: the site's own instance, which is where most questions belong
        if ($codes === []) {
            return self::main();
        }

        foreach ($codes as $cc) {
            if (in_array($cc, self::mainCountries(), true)) {
                return self::main();
            }

            $regional = self::regions()[$cc] ?? null;

            if ($regional !== null) {
                return ['url' => $regional, 'local' => true];
            }
        }

        return ['url' => self::PUBLIC_URL, 'local' => false];
    }

    /** ISO2 codes the main container holds: NOMINATIM_LOCAL_COUNTRIES=my,sg,bn */
    public static function mainCountries(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower((string) config('services.nominatim.countries', 'my,sg,bn'))))));
    }

    /** ISO2 -> base URL for the regional containers: NOMINATIM_REGIONS=au=http://127.0.0.1:8091,gb=http://127.0.0.1:8092 */
    public static function regions(): array
    {
        $out = [];

        foreach (array_filter(array_map('trim', explode(',', (string) config('services.nominatim.regions', '')))) as $pair) {
            [$cc, $url] = array_pad(explode('=', $pair, 2), 2, null);

            if ($cc !== null && $url !== null && $url !== '') {
                $out[strtolower(trim($cc))] = rtrim(trim($url), '/');
            }
        }

        return $out;
    }

    private static function main(): array
    {
        $url = rtrim((string) config('services.nominatim.url', self::PUBLIC_URL), '/');

        return ['url' => $url, 'local' => str_contains($url, '127.0.0.1') || str_contains($url, 'localhost')];
    }
}

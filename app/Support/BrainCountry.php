<?php

namespace App\Support;

use App\Services\Geo\SourceCountry;
use Illuminate\Support\Facades\DB;

/**
 * Which country the resource centre is looking at. "All" is the site as a
 * whole (the statistics); a country is that country's prompt, playbook,
 * rules and corrections. Chosen from the switch beside Overview and kept in
 * the session; ?country=SG on any resource-centre URL sets it.
 */
final class BrainCountry
{
    public const ALL = 'ALL';

    public static function current(): string
    {
        $q = strtoupper(trim((string) request()->query('country', '')));

        if ($q !== '' && ($q === self::ALL || preg_match('/^[A-Z]{2}$/', $q))) {
            session(['brain_country' => $q]);

            return $q;
        }

        return strtoupper((string) session('brain_country', self::ALL));
    }

    /** ISO2 or null for "All". */
    public static function iso2(): ?string
    {
        $c = self::current();

        return $c === self::ALL ? null : $c;
    }

    /** The countries the switch offers: the site's own, then every country a source is registered for. */
    public static function options(): array
    {
        $codes = SourceCountry::siteCountries();

        foreach (DB::table('sources')->whereNotNull('country')->distinct()->orderBy('country')->pluck('country') as $c) {
            $codes[] = strtoupper((string) $c);
        }

        $out = [];

        foreach (array_values(array_unique($codes)) as $c) {
            $iso3 = \App\Services\Geo\Boundaries\Iso3166::iso3($c);
            $out[$c] = ($iso3 !== null ? \App\Services\Geo\Boundaries\Iso3166::name($iso3) : null) ?? $c;
        }

        return $out;
    }

    public static function name(?string $iso2): string
    {
        if ($iso2 === null) {
            return 'All countries';
        }

        $iso3 = \App\Services\Geo\Boundaries\Iso3166::iso3($iso2);

        return ($iso3 !== null ? \App\Services\Geo\Boundaries\Iso3166::name($iso3) : null) ?? $iso2;
    }
}

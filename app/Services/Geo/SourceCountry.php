<?php

namespace App\Services\Geo;

use App\Services\Geo\Boundaries\Iso3166;
use Illuminate\Support\Facades\DB;

/**
 * Where a publisher is from, as the prior for everything its text leaves
 * unsaid.
 *
 * The New Straits Times is a Malaysian paper. It writes Malaysian news without
 * naming the country - "the court in Jasin", "the stadium in Kuantan" - and
 * names the country or the city when it reports from abroad. So when a name
 * from a Malaysian paper says nothing about where, the honest reading is
 * Malaysia; the same bare name from the Straits Times reads as Singapore. The
 * masthead is evidence, and until now nothing read it.
 *
 * Three uses, all as a DEFAULT, never as an override of what the text says:
 * the prompt tells the model whose paper it is reading; the geocoder searches
 * the publisher's country first; the boundary check treats an unqualified name
 * as claiming that country.
 *
 * International outlets - a wire, a sports federation - have no home, and get
 * no prior: null here means "search the world, claim nothing".
 */
class SourceCountry
{
    /** The site's own country, for publishers the sources table does not know. */
    public const SITE = 'MY';

    /**
     * Every country the site puts on its map, ISO2 upper: SITE_COUNTRIES=MY,SG.
     * A place in one of these is pinned; a place elsewhere is "foreign", a
     * national story found under its topic. Singapore joined on 3 Sep 2026
     * with its own sources; Britain and Australia follow when their map
     * engines and sources are on.
     *
     * @return list<string>
     */
    public static function siteCountries(): array
    {
        $list = array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', (string) config('services.site_countries', self::SITE)))));

        return $list === [] ? [self::SITE] : $list;
    }

    /** @var array<string, ?string> source name => ISO2 or null */
    private static array $cache = [];

    public static function iso2(?string $sourceName): ?string
    {
        $name = trim((string) $sourceName);

        if ($name === '') {
            return self::SITE;
        }

        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }

        $row = DB::table('sources')->where('name', $name)->first(['country']);

        // Unknown to the table: the site's own event and reader sources, all
        // domestic. A publisher the table knows but has left blank is
        // international by declaration.
        $iso2 = $row === null ? self::SITE : ($row->country ? strtoupper($row->country) : null);

        return self::$cache[$name] = $iso2;
    }

    public static function iso3(?string $sourceName): ?string
    {
        $two = self::iso2($sourceName);

        return $two === null ? null : Iso3166::iso3($two);
    }

    /** "a Malaysian publisher", "a Singaporean publisher", "an international publisher". */
    public static function describe(?string $sourceName): string
    {
        $two = self::iso2($sourceName);

        if ($two === null) {
            return 'an international publisher with no home country';
        }

        $country = Iso3166::name(Iso3166::iso3($two) ?? '') ?? $two;

        return "a publisher based in {$country}";
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}

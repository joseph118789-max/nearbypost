<?php

namespace App\Services\Geo;

use App\Services\Geo\Boundaries\Iso3166;

/**
 * Which country a story belongs to, pin or no pin.
 *
 * A pinned story belongs where its pin is. Everything else - a national story
 * with no place, a discarded story that was never geocoded, a story waiting
 * on a person - still belongs somewhere, and the Countries report has to
 * count it there. The place text says so when it names a country; when it
 * does not, the publisher's own country does, by the same reading the model
 * and the geocoder use: an unqualified place in a Malaysian paper is in
 * Malaysia.
 *
 * ISO3, to match the boundaries table. Null only for an international outlet
 * whose text names no country - which is genuinely nowhere.
 */
class ClaimCountry
{
    public static function of(?string $placeText, ?string $sourceName, ?string $pinnedIso3 = null): ?string
    {
        if ($pinnedIso3 !== null && $pinnedIso3 !== '') {
            return strtoupper($pinnedIso3);
        }

        $home = SourceCountry::iso2($sourceName);
        $iso2 = CountryCode::forPlace((string) $placeText, $home);

        return $iso2 === null ? null : Iso3166::iso3($iso2);
    }
}

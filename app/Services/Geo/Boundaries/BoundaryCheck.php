<?php

namespace App\Services\Geo\Boundaries;

use App\Services\Geo\CountryCode;
use Illuminate\Support\Facades\DB;

/**
 * Does a pin fall inside the place the story named? Geometrically.
 *
 * Two halves. First, what the name CLAIMS: a place text like "Litar Brno,
 * Republik Czech" claims the Czech Republic; "Mahkamah Majistret, Jasin,
 * Melaka" claims Malaysia and, within it, Melaka. Second, where the pin IS:
 * which country and state polygon actually contain the point. If the claim
 * names a state and the point is in a different one, the answer is wrong,
 * whatever the geocoder said and however confident it was.
 *
 * Three verdicts, and the third matters as much as the first two:
 *
 *   inside    the point is in every area the name claims
 *   outside   the point is not in one of them - REFUSE the answer
 *   unknown   the name claims nothing this table knows (no country, or a
 *             state the source has no polygon for) - the caller falls back
 *             to the text-based check, which is weaker but not nothing
 *
 * Every verdict carries where the point actually is, so the person reading
 * the review queue sees "expected Czech Republic, point is in Kuala Lumpur"
 * and not just "rejected".
 */
class BoundaryCheck
{
    public function __construct(private ?BoundaryStore $store = null)
    {
        $this->store ??= new BoundaryStore();
    }

    /**
     * What the name claims.
     *
     * @return array{country: ?string, state: ?string, state_name: ?string, parts: list<string>}
     */
    public function expectation(string $placeText, ?string $home = 'my'): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $placeText)), fn ($p) => $p !== ''));

        $iso2 = CountryCode::forPlace($placeText, $home);
        $iso3 = $iso2 === null ? null : Iso3166::iso3($iso2);

        // A named state of the site's own country outranks the masthead.
        // "Mersing waters near the Customs Jetty, Mersing, Johor" from a
        // Singapore paper was claimed for Singapore, and Johor then looked
        // up among Singapore's regions and found nothing. Johor is Johor.
        $site = Iso3166::iso3(\App\Services\Geo\SourceCountry::SITE);

        if ($iso3 !== $site && CountryCode::forPlace($placeText, null) === null && $this->store->hasStates($site)) {
            foreach (array_reverse($parts) as $part) {
                if ($this->store->area($site, 1, preg_replace('/^(w\.?p\.?|wilayah persekutuan|negeri)\s+/iu', '', $part)) !== null) {
                    $iso3 = $site;
                    break;
                }
            }
        }

        // Malaysia by default is a DEFAULT, not a claim. "Stamford Bridge,
        // London" names no country and the model should have written one -
        // but the pin in London is right, and refusing it because a default
        // said Malaysia would be the boundary check manufacturing an error.
        // So a Malaysian claim is enforced only when the name actually
        // contains a Malaysian place: a state, or a name the alias table
        // knows. "Pekan Baru, Teluk Intan" does; "Flushing Meadows" does not.
        $explicit = $this->namesACountry($parts);
        $anchored = !$explicit && $iso3 !== null ? $this->namesHome($parts, $iso3) : $explicit;

        $out = ['country' => $iso3, 'state' => null, 'state_name' => null, 'parts' => $parts,
                'explicit' => $explicit, 'anchored' => $anchored];

        if ($iso3 === null || !$this->store->hasCountry($iso3)) {
            return $out;
        }

        // The state is whichever trailing component names one. Walk from the
        // end: "venue, town, state, country" - the state is the last part that
        // is not the country.
        if ($this->store->hasStates($iso3)) {
            // The state is a trailing component - or the only one. "Sarawak"
            // on its own is Sarawak; scanning only from the second part meant
            // a whole-state story matched nothing and was filed as national.
            $floor = count($parts) > 1 ? 1 : 0;

            for ($i = count($parts) - 1; $i >= $floor; $i--) {
                $part = $parts[$i];

                if (CountryCode::codeFor($part) !== null) {
                    continue;   // that is the country, not a state
                }

                // "Presint 5, Putrajaya" - the state may be written with a
                // prefix the source does not use. Try the part as-is, then
                // without W.P./Negeri/State of.
                foreach ([$part, preg_replace('/^(w\.?p\.?|wilayah persekutuan|negeri|state of|province of|provinsi)\s+/iu', '', $part)] as $try) {
                    $area = $this->store->area($iso3, 1, (string) $try);

                    if ($area !== null) {
                        $out['state']      = $area['code'];
                        $out['state_name'] = $area['name'];

                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    private function namesACountry(array $parts): bool
    {
        foreach ($parts as $p) {
            if (CountryCode::codeFor($p) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does any component name a state of the home country, or - for the
     * site's own country - a place the alias table has been taught?
     */
    private function namesHome(array $parts, string $iso3): bool
    {
        foreach ($parts as $p) {
            if ($this->store->area($iso3, 1, $p) !== null) {
                return true;
            }
        }

        return $iso3 === 'MYS' ? $this->namesMalaysia($parts) : false;
    }

    /** Does any component name a Malaysian state, or a place the site has been taught? */
    private function namesMalaysia(array $parts): bool
    {
        static $known = null;

        if ($known === null) {
            $known = [];

            try {
                foreach (DB::table('location_aliases')->where('is_active', true)->get(['alias_text', 'canonical_name']) as $r) {
                    $known[BoundaryLoader::key($r->alias_text)] = true;
                    $known[BoundaryLoader::key($r->canonical_name)] = true;
                }
            } catch (\Throwable $e) {
                // no table, no knowledge - the state test below still applies
            }
        }

        foreach ($parts as $p) {
            $k = BoundaryLoader::key($p);

            if ($k === 'malaysia' || isset($known[$k]) || $this->store->area('MYS', 1, $p) !== null) {
                return true;
            }

            // "Sentul, Kuala Lumpur" written as one part with the state inside it.
            foreach (explode(' ', $k) as $w) {
                if (strlen($w) > 3 && isset($known[$w])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The state of a place name, using what the geocode cache already knows.
     *
     * "Kuching", "Sibu", "Johan Setia" carry no state in their text, but every
     * one of them has been looked up before, and Nominatim's answer - state
     * included - is in the cache. No request is made: a name the cache does
     * not hold simply gives null.
     */
    public function stateOfKnownPlace(string $placeText, ?string $home = 'my'): ?array
    {
        $e = $this->expectation($placeText, $home);

        if ($e['state'] !== null || $e['country'] === null) {
            return $e['state'] !== null ? ['country' => $e['country'], 'state' => $e['state'], 'state_name' => $e['state_name']] : null;
        }

        $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\s*,\s*Malaysia\s*$/i', '', $placeText))));
        $hit = DB::table('geocode_cache')->where('place_key', $key)->where('status', 'success')->first(['state', 'country', 'lat', 'lng']);

        if (!$hit) {
            return null;
        }

        // The pin's polygon is the surest answer; the cached state name the
        // next best.
        if ($hit->lat !== null && $hit->lng !== null) {
            $at = $this->store->locate((float) $hit->lat, (float) $hit->lng);

            if ($at['country'] === $e['country'] && $at['state'] !== null) {
                return ['country' => $at['country'], 'state' => $at['state'], 'state_name' => $at['state_name']];
            }
        }

        if ($hit->state) {
            $area = $this->store->area($e['country'], 1, (string) $hit->state);

            if ($area !== null) {
                return ['country' => $e['country'], 'state' => $area['code'], 'state_name' => $area['name']];
            }
        }

        return null;
    }

    /**
     * The verdict for one pin.
     *
     * @return array{verdict: 'inside'|'outside'|'unknown', expected: array, actual: array, detail: string}
     */
    public function verify(string $placeText, float $lat, float $lng, ?string $home = 'my'): array
    {
        $expected = $this->expectation($placeText, $home);
        $actual   = $this->store->locate($lat, $lng);

        $where = $actual['country']
            ? ($actual['state_name'] ? "{$actual['state_name']}, {$actual['country_name']}" : $actual['country_name'])
            : 'no known country (sea, or an unloaded country)';

        // No country to hold the point to: nothing to say.
        if ($expected['country'] === null || !$this->store->hasCountry($expected['country'])) {
            return ['verdict' => 'unknown', 'expected' => $expected, 'actual' => $actual,
                    'detail' => "no boundary for the country named; point is in {$where}"];
        }

        // A default that nothing in the name supports cannot REFUSE a point.
        // It can still confirm one: a bare "Tasik Kenyir" whose point is in
        // Terengganu is fine, and says so.
        if (!$expected['anchored']) {
            if ($actual['country'] === $expected['country']) {
                return ['verdict' => 'inside', 'expected' => $expected, 'actual' => $actual,
                        'detail' => 'inside ' . Iso3166::name($expected['country']) . ($actual['state_name'] ? " ({$actual['state_name']})" : '')];
            }

            return ['verdict' => 'unknown', 'expected' => $expected, 'actual' => $actual,
                    'detail' => "name gives no country and no Malaysian place; point is in {$where}"];
        }

        // A point no polygon contains is at sea, on reclaimed land the
        // coastline predates, or in a country not loaded yet. None of those
        // is evidence the answer is wrong, and refusing on them put the Sabah
        // convention centre - on Kota Kinabalu's waterfront - outside Malaysia.
        if ($actual['country'] === null) {
            return ['verdict' => 'unknown', 'expected' => $expected, 'actual' => $actual,
                    'detail' => "point is in {$where}; cannot be judged"];
        }

        $countryOk = $this->store->inside($lat, $lng, $expected['country'], 0, $expected['country']);

        if ($countryOk === false) {
            return ['verdict' => 'outside', 'expected' => $expected, 'actual' => $actual,
                    'detail' => 'expected ' . Iso3166::name($expected['country']) . ", point is in {$where}"];
        }

        if ($expected['state'] !== null) {
            $stateOk = $this->store->inside($lat, $lng, $expected['country'], 1, $expected['state']);

            if ($stateOk === false) {
                return ['verdict' => 'outside', 'expected' => $expected, 'actual' => $actual,
                        'detail' => "expected {$expected['state_name']}, point is in {$where}"];
            }

            return ['verdict' => 'inside', 'expected' => $expected, 'actual' => $actual,
                    'detail' => "inside {$expected['state_name']}, " . Iso3166::name($expected['country'])];
        }

        return ['verdict' => 'inside', 'expected' => $expected, 'actual' => $actual,
                'detail' => 'inside ' . Iso3166::name($expected['country']) . ($actual['state_name'] ? " ({$actual['state_name']})" : '')];
    }
}

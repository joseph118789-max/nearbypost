<?php

namespace App\Services\Geo;

/**
 * Does the answer the geocoder gave match the name it was asked about?
 *
 * "Victoria Bridge, Enggor, Kuala Kangsar, Perak" came back as 53.48, -2.25 -
 * Manchester. The name says Perak in as many words; the reply is nine thousand
 * kilometres away, and it was stored, served and mapped without complaint,
 * because nothing between the geocoder and the map ever compared the two.
 *
 * This is the failure mode that hides: a geocoder does not say "I don't know".
 * It loosens the search until something matches, and then answers confidently.
 * A miss is visible - it lands in the review queue and somebody looks at it. A
 * confident wrong answer looks exactly like a right one.
 *
 * So the check is narrow and cheap: if the NAME says Malaysia, the COORDINATES
 * have to be in Malaysia. It does not try to verify the state, which would need
 * real boundaries; it catches the answers that are on the wrong landmass, which
 * is what actually happens.
 */
class GeoPlausibility
{
    /**
     * A box around Malaysia, peninsula and Borneo, drawn loose on purpose.
     *
     * It overlaps Singapore, Brunei and the Riau and Kalimantan edges of
     * Indonesia. That is the right trade: a neighbouring town a few kilometres
     * over a border is a plausible geocode worth allowing through, and the
     * answers worth stopping - England, South Kalimantan, Australia - are
     * nowhere near it.
     */
    private const MIN_LAT = 0.5;
    private const MAX_LAT = 7.6;
    private const MIN_LNG = 99.3;
    private const MAX_LNG = 119.6;

    /**
     * A reason the coordinates contradict the name, or null if they agree.
     */
    public static function contradiction(string $placeText, float $lat, float $lng): ?string
    {
        if (!self::namesMalaysia($placeText)) {
            return null;
        }

        if (self::insideMalaysia($lat, $lng)) {
            return null;
        }

        return sprintf(
            'the name says Malaysia but %.4f,%.4f is outside it',
            $lat,
            $lng
        );
    }

    /**
     * Does the country the geocoder found disagree with the one in the name?
     *
     * The Malaysia check below only ever asked one question: does a Malaysian
     * NAME have Malaysian COORDINATES. It could not see the mirror image, and
     * the mirror image is worse - "Hospital Pengajar Universiti Tribhuvan,
     * Kathmandu, Nepal" was answered with 3.15, 101.70, which is Kuala Lumpur.
     * The geocoder had matched the Malay words "Hospital Pengajar Universiti"
     * to a local teaching hospital and quietly dropped Nepal. A story about a
     * hospital wall in Kathmandu then appeared 8km from a reader in Desa
     * ParkCity.
     *
     * Nothing downstream could catch that. The name is not a Malaysian name, so
     * the Malaysia rule stayed silent, and the coordinates are perfectly
     * ordinary coordinates. But the ANSWER carries a country of its own now,
     * and comparing the two costs nothing.
     */
    public static function wrongCountry(string $placeText, ?string $resolvedCountry): ?string
    {
        $resolved = trim((string) $resolvedCountry);

        if ($resolved === '') {
            return null;   // the provider did not say; nothing to compare
        }

        $named = self::countryNamedIn($placeText);

        if ($named === null || self::sameCountry($named, $resolved)) {
            return null;
        }

        return sprintf('the name says %s but the coordinates are in %s', $named, $resolved);
    }

    /** The country a place name spells out, if it spells one out at all. */
    private static function countryNamedIn(string $placeText): ?string
    {
        foreach (array_map('trim', explode(',', $placeText)) as $part) {
            if ($part === '') {
                continue;
            }

            foreach (PlaceScale::COUNTRIES as $country) {
                if (mb_strtolower($part) === mb_strtolower($country)) {
                    return $country;
                }
            }
        }

        return null;
    }

    /**
     * One country under two names. The catalogue of places this site reads is
     * multilingual, and so are the answers - "Amerika Syarikat" and "United
     * States" are not a disagreement.
     */
    private static function sameCountry(string $a, string $b): bool
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);

        if ($a === $b) {
            return true;
        }

        $aliases = [
            'malaysia'        => ['malaysia'],
            'united states'   => ['amerika syarikat', 'usa', 'us', 'united states of america'],
            'united kingdom'  => ['britain', 'great britain', 'uk', 'england', 'scotland', 'wales'],
            'germany'         => ['jerman', 'deutschland'],
            'france'          => ['perancis', 'france'],
            'japan'           => ['jepun'],
            'china'           => ['tiongkok', 'cina'],
            'south korea'     => ['korea selatan', 'korea'],
            'thailand'        => ['thai'],
            'netherlands'     => ['belanda', 'holland'],
            'spain'           => ['sepanyol'],
            'italy'           => ['itali'],
            'india'           => ['hindi'],
            'singapore'       => ['singapura'],
            'indonesia'       => ['indonesia'],
            'philippines'     => ['filipina'],
        ];

        foreach ($aliases as $canonical => $forms) {
            $set = array_merge([$canonical], $forms);

            if (in_array($a, $set, true) && in_array($b, $set, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A name that says it is abroad, answered with coordinates in Malaysia.
     *
     * The country comparison above needs the provider to say which country it
     * found, and not every path does. This one needs nothing but the answer:
     * "Bidur, Nuwakot district, Nepal" came back at 4.12, 101.28 - Bidor in
     * Perak, matched on the first word alone - and no country was returned to
     * argue with.
     *
     * Neighbours are exempt. The Malaysia box is drawn loose on purpose and
     * overlaps Singapore, Brunei and the Indonesian edges, so a Johor Bahru
     * story that geocodes a few hundred metres over the causeway is fine.
     */
    public static function foreignNameInMalaysia(string $placeText, float $lat, float $lng): ?string
    {
        if (!self::insideMalaysia($lat, $lng)) {
            return null;
        }

        $neighbours = ['malaysia', 'singapore', 'singapura', 'brunei', 'indonesia'];

        foreach (array_map('trim', explode(',', $placeText)) as $part) {
            foreach (PlaceScale::COUNTRIES as $country) {
                if (mb_strtolower($part) !== mb_strtolower($country)) {
                    continue;
                }

                if (in_array(mb_strtolower($country), $neighbours, true)) {
                    return null;
                }

                return sprintf('the name says %s but %.4f,%.4f is in Malaysia', $country, $lat, $lng);
            }
        }

        return null;
    }

    public static function insideMalaysia(float $lat, float $lng): bool
    {
        return $lat >= self::MIN_LAT && $lat <= self::MAX_LAT
            && $lng >= self::MIN_LNG && $lng <= self::MAX_LNG;
    }

    /**
     * Does this name claim to be in Malaysia?
     *
     * Checked against the raw text before any prefix is stripped - taking
     * "Negeri" off "Negeri Sembilan" leaves "Sembilan", which is the bug that
     * MalaysianStates was written to avoid.
     */
    private static function namesMalaysia(string $placeText): bool
    {
        $needles = [
            'malaysia', 'johor', 'kedah', 'kelantan', 'melaka', 'malacca',
            'negeri sembilan', 'pahang', 'perak', 'perlis', 'penang',
            'pulau pinang', 'sabah', 'sarawak', 'selangor', 'terengganu',
            'kuala lumpur', 'putrajaya', 'labuan',
        ];

        $haystack = mb_strtolower($placeText);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}

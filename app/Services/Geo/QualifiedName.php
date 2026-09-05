<?php

namespace App\Services\Geo;

/**
 * Finish a place name using the state the geocoder already knew.
 *
 * "Tasik Kenyir" is not one place a reader can be sent to - the owner's words,
 * and they are right: a bare name will be matched against the wrong one sooner
 * or later, and the map does not warn anybody when it happens. "Kota Baru"
 * alone resolved to Kotabaru in Indonesia. The cure is the state on the end.
 *
 * The playbook asks the classifier for it and the classifier keeps forgetting -
 * it was measured at 86% of names carrying a state, then 8% after a wording
 * change, then somewhere in between. That is not a rule anybody can rely on.
 *
 * But by the time a name has been geocoded, the state is not a matter of
 * opinion: Nominatim returned it, in the address record, and the request has
 * been asking for `addressdetails` all along. So the name is completed from the
 * answer rather than asked for again.
 *
 * This only ever ADDS a qualifier to a name that resolved. It cannot move a pin
 * and it cannot invent a state, because the state came back with the
 * coordinates it is describing.
 */
class QualifiedName
{
    /**
     * Add the state - or, abroad, the country - to a name that stands alone.
     *
     * A name that already says what contains it does not need more. "Parit
     * Saidi, Senggarang, Batu Pahat" is three levels deep and cannot be
     * mistaken for anywhere else; adding "Johor" makes it longer and no
     * clearer. "Tasik Kenyir" has nothing above it at all, and that is the
     * difference - there is more than one lake, more than one Sungai Siput, and
     * a search with nothing to narrow it lands wherever it lands.
     *
     * So the test is not "does it end in a state". It is whether the name
     * carries any reference above itself.
     */
    public static function complete(string $name, ?string $state, ?string $country): string
    {
        $name = trim($name);

        if ($name === '' || self::hasReference($name)) {
            return $name;
        }

        $tail = self::qualifierFor($state, $country);

        if ($tail === null || self::alreadyMentions($name, $tail)) {
            return $name;
        }

        return $name . ', ' . $tail;
    }

    /**
     * Does the name already say what it is part of?
     *
     * A comma is how every stage of this pipeline writes containment - "Lebuh
     * China, George Town" - so one is enough to say the name is not adrift.
     */
    private static function hasReference(string $name): bool
    {
        $parts = array_filter(array_map('trim', explode(',', $name)), fn ($p) => $p !== '');

        return count($parts) > 1;
    }

    /**
     * Inside Malaysia the state is what disambiguates; abroad it is the
     * country. A reader here does not need "Selangor, Malaysia" - the country
     * is assumed - but they do need "Doha, Qatar".
     */
    private static function qualifierFor(?string $state, ?string $country): ?string
    {
        $country = trim((string) $country);
        $state   = trim((string) $state);

        $isMalaysian = $country === '' || self::isMalaysia($country);

        if ($isMalaysian) {
            return $state !== '' ? $state : null;
        }

        return $country !== '' ? $country : null;
    }

    private static function isMalaysia(string $country): bool
    {
        return in_array(mb_strtolower($country), ['malaysia', 'malaysia '], true);
    }

    /**
     * Is the qualifier already in the name, under any of its usual spellings?
     *
     * Nominatim answers "Pulau Pinang" where an article says "Penang", and
     * "Melaka" where it says "Malacca". Appending blindly would produce "Penang
     * International Airport, Penang, Pulau Pinang".
     */
    private static function alreadyMentions(string $name, string $qualifier): bool
    {
        $haystack = mb_strtolower($name);

        foreach (self::spellings($qualifier) as $form) {
            if (str_contains($haystack, mb_strtolower($form))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function spellings(string $qualifier): array
    {
        $forms = [$qualifier];

        $pairs = [
            'pulau pinang'    => ['Penang'],
            'penang'          => ['Pulau Pinang'],
            'melaka'          => ['Malacca'],
            'malacca'         => ['Melaka'],
            'kuala lumpur'    => ['KL', 'Wilayah Persekutuan'],
            'negeri sembilan' => ['N. Sembilan', 'N Sembilan'],
        ];

        foreach ($pairs[mb_strtolower($qualifier)] ?? [] as $alt) {
            $forms[] = $alt;
        }

        return $forms;
    }
}

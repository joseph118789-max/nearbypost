<?php

namespace App\Services\Geo;

/**
 * Does the answer sit inside the place the question named?
 *
 * A geocoder never says "I don't know". Loosen the search and it answers with
 * something - and the something is confident, well-formed, and wrong. Measured
 * on 116 names no source could place: dropping the town from the query, or
 * asking Photon's fuzzy index, produced 57 answers of which 45 were somewhere
 * else entirely. "Mahkamah Majistret, Jasin, Melaka" came back as a court in
 * Chow Kit; "Kampung Tanduo, Lahad Datu, Sabah" came back in Kedah. Served,
 * those would have put a Melaka court case 1.7 km from a reader in Bukit
 * Bintang.
 *
 * So a loosened search is allowed on one condition: the answer must be inside
 * the town or state the story named. The narrowest anchor after the place
 * itself ("Jasin") must appear in the answer; failing that the state may; and
 * no OTHER Malaysian state may appear. With that rule the same 57 answers
 * split 11 right, 45 rejected - which is the difference between a shortcut
 * and a trap.
 */
class PlaceContainment
{
    private const STATES = [
        'johor', 'kedah', 'kelantan', 'melaka', 'negeri sembilan', 'pahang',
        'perak', 'perlis', 'pulau pinang', 'sabah', 'sarawak', 'selangor',
        'terengganu', 'kuala lumpur', 'putrajaya', 'labuan',
    ];

    /** Spellings that mean the same state, so "Penang" anchors "Pulau Pinang". */
    private const ALIASES = [
        'penang' => 'pulau pinang', 'malacca' => 'melaka', 'n. sembilan' => 'negeri sembilan',
        'kl' => 'kuala lumpur', 'pj' => 'petaling jaya', 'jb' => 'johor bahru',
        'wilayah persekutuan kuala lumpur' => 'kuala lumpur', 'w.p. kuala lumpur' => 'kuala lumpur',
    ];

    /**
     * @param string  $asked   the place text as the story gave it, "venue, town, state"
     * @param string  $answer  the geocoder's full display name for its answer
     * @param ?string $state   the state the geocoder reports, when it does
     */
    public static function holds(string $asked, string $answer, ?string $state = null): bool
    {
        $parts   = array_values(array_filter(array_map(fn ($p) => self::norm($p), explode(',', $asked))));
        $anchors = array_slice($parts, 1);

        // Nothing after the place itself: nothing to hold it against. That is
        // not a pass. A bare name is exactly the case a loosened search gets
        // most wrong, so the caller must not use one without an anchor.
        if ($anchors === []) {
            return false;
        }

        $haystack = self::norm($answer) . ' ' . self::norm((string) $state);

        $named = [];

        foreach (self::STATES as $s) {
            foreach ($anchors as $a) {
                if (str_contains($a, $s)) {
                    $named[] = $s;
                }
            }
        }

        // Any state the answer mentions that the question did not is a
        // different place, whatever else matches. "Kampung Ayer Merbau, Jasin,
        // Melaka" answered with "Kampung Ayer Itam, ... Kedah" shares two words
        // and is 400 km away.
        foreach (self::STATES as $s) {
            if (in_array($s, $named, true)) {
                continue;
            }

            if (self::mentions($haystack, $s)) {
                return false;
            }
        }

        // The narrowest anchor must appear - the town, the district. The
        // state is enough ONLY when the state is all the question gave. It
        // used to count whenever it appeared, and the day the loosened
        // queries started carrying the state, every answer inside the state
        // "held": a T-junction near Kota Belud was accepted at Kinabatangan,
        // 250 km away, because both are in Sabah.
        $towns = [];

        foreach ($anchors as $a) {
            if ($a === '' || self::isState($a)) {
                continue;
            }

            // A road between two towns names both of them.
            if (preg_match('/\b(road|jalan|highway|lebuhraya|expressway|trunk)\b/u', $a)) {
                foreach (preg_split('/\s*[-\x{2013}\x{2014}\/]\s*/u', preg_replace('/\b(road|jalan|highway|lebuhraya|expressway|trunk|the|federal|persekutuan)\b/u', '', $a)) as $t) {
                    $t = trim(preg_replace('/\s+/', ' ', $t));

                    if (mb_strlen($t) >= 4) {
                        $towns[] = $t;
                    }
                }

                continue;
            }

            $towns[] = $a;
        }

        foreach ($towns !== [] ? $towns : $anchors as $a) {
            if ($a !== '' && self::mentions($haystack, $a)) {
                return true;
            }
        }

        return false;
    }

    /** "sabah", "wilayah persekutuan kuala lumpur", "johor darul takzim" - a state, however dressed. */
    private static function isState(string $part): bool
    {
        foreach (self::STATES as $s) {
            if (preg_match('/^(wilayah persekutuan |w\.p\. |negeri |state of )?' . preg_quote($s, '/') . '( darul [a-z ]+| state)?$/u', $part)) {
                return true;
            }
        }

        return false;
    }

    private static function mentions(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack);
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s);

        foreach (self::ALIASES as $from => $to) {
            $s = preg_replace('/(?<![\p{L}])' . preg_quote($from, '/') . '(?![\p{L}])/u', $to, $s);
        }

        return $s;
    }
}

<?php

namespace App\Services\Community;

/**
 * "Improve the title strongly; improve the body lightly; never change the
 * facts." The model is told that; this checks it. A number, a capitalised
 * name or a time in the improved text that the original never had is an
 * added claim, and an added claim means the original text is published,
 * not the model's.
 */
final class FactGuard
{
    /** @return list<string> the tokens the improved text introduces */
    public static function addedClaims(string $originalTitle, string $originalBody, string $improvedTitle, string $improvedBody, string $place = ''): array
    {
        $orig = mb_strtolower($originalTitle . ' ' . $originalBody . ' ' . $place);
        $have = self::tokens($orig);
        $out  = [];

        foreach (self::tokens(mb_strtolower($improvedTitle . ' ' . $improvedBody)) as $tok) {
            if (in_array($tok, $have, true)) {
                continue;
            }

            // a number the writer never gave (10:30 → 10, 30 both fine if present)
            if (preg_match('/^\d+([.,:]\d+)?$/u', $tok) && !str_contains($orig, $tok)) {
                $out[] = $tok;
            }
        }

        // proper names: capitalised words of the improved text absent from the original
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $improvedTitle . ' ' . $improvedBody, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            if (mb_strlen($w) >= 4 && preg_match('/^\p{Lu}/u', $w) && !in_array(mb_strtolower($w), $have, true)
                && !in_array(mb_strtolower($w), self::ALLOWED, true)) {
                $out[] = $w;
            }
        }

        // words that turn a report into an outcome
        foreach (self::OUTCOME_WORDS as $w) {
            if (preg_match('/\b' . $w . '\b/iu', $improvedTitle . ' ' . $improvedBody) && !preg_match('/\b' . $w . '\b/iu', $orig)) {
                $out[] = $w;
            }
        }

        return array_values(array_unique($out));
    }

    /** Words a cautious rewrite may add without adding a fact. */
    private const ALLOWED = ['reported', 'seen', 'appears', 'according', 'contributor', 'residents', 'area', 'near', 'nearby', 'morning',
        'afternoon', 'evening', 'night', 'today', 'large', 'road', 'jalan', 'kampung', 'taman', 'this', 'that', 'with', 'from', 'after',
        'before', 'around', 'about', 'plume', 'smoke', 'traffic', 'police', 'fire', 'flood', 'water', 'rescue', 'update'];

    private const OUTCOME_WORDS = ['destroy(?:ed|s)?', 'injur(?:ed|ies|y)', 'kill(?:ed|s)?', 'dead', 'death', 'died', 'arrested', 'charged',
        'confirmed', 'massive', 'huge', 'explod(?:ed|es|sion)', 'collaps(?:ed|es)', 'victims?'];

    /** @return list<string> */
    private static function tokens(string $s): array
    {
        // "." and ":" stay inside a token for 10.30 and 10:30, but never at its ends
        $out = [];

        foreach (preg_split('/[^\p{L}\p{N}.:]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $t = trim($t, '.:');

            if ($t !== '') {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }
}

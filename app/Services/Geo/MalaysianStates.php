<?php

namespace App\Services\Geo;

/**
 * The states and federal territories, and why a story is never pinned to one.
 *
 * The owner's rule: "we should set the rules of not pinpointing location for
 * state. If genuinely the news is for the state, classify as national news."
 *
 * The reasoning is the same one the whole product turns on. A reader is
 * standing somewhere small and asking what is happening near them. Sarawak is
 * 124,000 square kilometres; its centroid is jungle. Pinning a story there does
 * not tell a reader in Kuching that it concerns them - it drops a marker three
 * hundred kilometres away and hopes their radius is wide enough. A state-wide
 * story genuinely belongs to everyone in it, and "belongs to everyone" is what
 * national means here.
 *
 * This list is safe to hold as a list, where a list of institutions was not.
 * There are sixteen, the last one was created in 1984, and no new one appears
 * because a publisher writes a story a different way.
 *
 * Kept in one place because it is now consulted by three: the classifier, which
 * refuses a state as an answer; the multi-point server, which drops the state
 * when a story also names towns inside it; and anything later that needs to ask
 * the same question.
 */
class MalaysianStates
{
    /**
     * Every name a Malaysian state is written under, including the English and
     * Malay forms of the ones that differ.
     */
    public const NAMES = [
        'johor', 'johore',
        'kedah',
        'kelantan',
        'melaka', 'malacca',
        'negeri sembilan', 'negri sembilan',
        'pahang',
        'perak',
        'perlis',
        'penang', 'pulau pinang',
        'sabah',
        'sarawak',
        'selangor',
        'terengganu', 'trengganu',
        'kuala lumpur', 'wilayah persekutuan kuala lumpur',
        'putrajaya',
        'labuan',
        'wilayah persekutuan',
    ];

    /**
     * Is this label nothing more than a state?
     *
     * "Sarawak" is. "Kuching, Sarawak" is not - it names a city and says which
     * state it is in, which is exactly the form the classifier is asked to
     * produce. So only a label that is entirely a state name matches, after the
     * ornaments a publisher might add.
     */
    public static function isBareState(?string $label): bool
    {
        $text = mb_strtolower(trim((string) $label));

        if ($text === '') {
            return false;
        }

        $text = trim(preg_replace('/[.,]+$/u', '', $text));

        // The name as written, FIRST. Negeri Sembilan's name begins with the
        // very word the tidying below removes, and stripping it leaves
        // "sembilan", which is not a state and would have been served.
        if (in_array($text, self::NAMES, true)) {
            return true;
        }

        // Then the ornaments a publisher might add: "Negeri Sarawak",
        // "Sarawak State", "state of Perak", "Pahang Darul Makmur".
        $stripped = preg_replace('/^(negeri|state of|wilayah persekutuan|wilayah)\s+/u', '', $text);
        $stripped = trim(preg_replace('/\s+(state|darul\s+\w+)$/u', '', $stripped));

        return in_array($stripped, self::NAMES, true);
    }
}

<?php

namespace App\Services\Geo;

/**
 * Places too large for anyone to be near.
 *
 * The owner's rule for states - "if genuinely the news is for the state,
 * classify as national news" - is a rule about scale, not about Malaysia. A
 * country fails the same test more severely: an embassy advisory pinned to
 * "Qatar" puts a marker in the desert and tells a Malaysian in Doha nothing,
 * exactly as a haze story pinned to "Sarawak" told a reader in Kuching nothing.
 *
 * So this holds both lists and asks one question: is the label nothing but a
 * container?
 *
 * WHAT THIS IS NOT. It rejects a name standing alone, never a name used as a
 * qualifier. "Qatar" is refused; "Malaysian Embassy, Doha, Qatar" is exactly
 * what the classifier is asked to produce and passes untouched. The country at
 * the end is what lets a map find the place at the front.
 *
 * Both lists are safe to hold as lists, for the reason the states were: they
 * are closed sets that change over decades, not things a publisher invents by
 * wording a headline differently.
 */
class PlaceScale
{
    /**
     * Countries, with the names Malaysian papers actually use - including the
     * Malay forms, since a third of the feed is in Malay and "Amerika
     * Syarikat" is not a different place from "United States".
     */
    public const COUNTRIES = [
        // Southeast Asia and the neighbours
        'malaysia', 'singapore', 'singapura', 'indonesia', 'thailand', 'siam',
        'vietnam', 'philippines', 'filipina', 'brunei', 'cambodia', 'kemboja',
        'laos', 'myanmar', 'burma', 'timor leste', 'east timor',

        // Asia
        'china', 'republik rakyat china', 'japan', 'jepun', 'south korea',
        'korea selatan', 'north korea', 'korea utara', 'korea', 'taiwan',
        'hong kong', 'macau', 'india', 'pakistan', 'bangladesh', 'sri lanka',
        'nepal', 'bhutan', 'maldives', 'afghanistan', 'mongolia', 'kazakhstan',

        // West Asia and North Africa
        'saudi arabia', 'arab saudi', 'united arab emirates', 'uae', 'qatar',
        'kuwait', 'bahrain', 'oman', 'yemen', 'iran', 'iraq', 'syria', 'lebanon',
        'jordan', 'israel', 'palestine', 'palestin', 'turkey', 'turkiye',
        'egypt', 'mesir', 'libya', 'tunisia', 'algeria', 'morocco', 'sudan',

        // Europe
        'united kingdom', 'britain', 'great britain', 'england', 'scotland',
        'wales', 'northern ireland', 'ireland', 'france', 'perancis', 'germany',
        'jerman', 'italy', 'itali', 'spain', 'sepanyol', 'portugal',
        'netherlands', 'belanda', 'holland', 'belgium', 'switzerland',
        'austria', 'sweden', 'norway', 'denmark', 'finland', 'iceland',
        'poland', 'czech republic', 'czechia', 'slovakia', 'hungary', 'romania',
        'bulgaria', 'greece', 'croatia', 'serbia', 'russia', 'rusia', 'ukraine',
        'belarus', 'lithuania', 'latvia', 'estonia',

        // Africa
        'south africa', 'afrika selatan', 'nigeria', 'kenya', 'ethiopia',
        'ghana', 'tanzania', 'uganda', 'zimbabwe', 'somalia', 'senegal',

        // The Americas and Oceania
        'united states', 'united states of america', 'usa', 'us', 'america',
        'amerika syarikat', 'amerika', 'canada', 'kanada', 'mexico', 'brazil',
        'brazil', 'argentina', 'chile', 'colombia', 'peru', 'venezuela',
        'cuba', 'australia', 'new zealand', 'papua new guinea', 'fiji',

        // Kept in step with CountryCode::CODES - that map is the source of
        // truth for what counts as a country, and this list must not disagree
        // with it or a name can be "a country" to one guard and "a place" to
        // the other. See isBareCountry(), which now asks CountryCode first.
        'republik czech', 'czech', 'republik ceko', 'cina', 'thai', 'republik korea',
        'emiriah arab bersatu', 'turki', 'swiss', 'yunani', 'ukraina', 'maghribi',
        'republik dominican', 'dominican republic', 'uk', 'republik afrika selatan',
    ];

    /** Is the label nothing but a whole country? */
    public static function isBareCountry(?string $label): bool
    {
        $text = self::tidy($label);

        if ($text === '') {
            return false;
        }

        if (in_array($text, self::COUNTRIES, true)) {
            return true;
        }

        // The map that resolves names to codes knows every spelling and every
        // prefix this site has met; if it can name the country, it is one.
        return CountryCode::codeFor($text) !== null;
    }

    /**
     * Too large for a reader to be near it: a Malaysian state, or any country.
     *
     * The one question the classifier and the multi-point server both need to
     * ask, so they cannot answer it differently.
     */
    public static function isTooBigToBeNear(?string $label): bool
    {
        return MalaysianStates::isBareState($label) || self::isBareCountry($label);
    }

    private static function tidy(?string $label): string
    {
        $text = mb_strtolower(trim((string) $label));
        $text = preg_replace('/[.,]+$/u', '', $text);

        return trim((string) $text);
    }
}

<?php

namespace App\Services\Marketplace;

/**
 * What a food listing may and may not claim. Spec §9.2, §9.3, §18.3.
 *
 * ⛔⛔ "MUSLIM-OWNED" IS NOT "HALAL CERTIFIED", AND NOTHING IN THIS SYSTEM MAY
 * EVER TURN ONE INTO THE OTHER.
 *
 * Spec §9.2 states it twice, and §18.3 lists it among the rewrites the AI is
 * forbidden to make. The reason is not pedantry: in Malaysia halal
 * certification is issued by JAKIM and the state religious authorities, using
 * it without certification is an offence, and a Muslim family cooking at home
 * is making a truthful statement about themselves that is not a statement about
 * their kitchen's certification. Collapsing the two would put words in a
 * provider's mouth that could get them prosecuted, and would mislead a reader
 * who is relying on the claim.
 *
 * So the four states are separate values, the wording for each is fixed here,
 * and a certification claim needs evidence before it can be shown.
 */
class FoodClaims
{
    /** Spec §9.2, exactly these four and no fifth. */
    public const HALAL_STATES = [
        'certified'    => 'Halal certification confirmed',
        'muslim_owned' => 'The provider states this is Muslim-owned. No halal certification has been confirmed.',
        'none'         => 'No halal certification provided',
        'unspecified'  => 'Not specified',
    ];

    /**
     * ⛔ Only ONE of the four may be displayed as a confirmed certification, and
     * only when a moderator has confirmed it against the certificate. Everything
     * else says what it is.
     */
    public static function requiresEvidence(string $state): bool
    {
        return $state === 'certified';
    }

    /**
     * The wording a reader sees.
     *
     * @param  bool  $evidenceConfirmed  whether a person checked the certificate
     */
    public static function halalWording(string $state, bool $evidenceConfirmed = false): string
    {
        // ⛔ A certification CLAIM without confirmed evidence does not get the
        // certified wording. It falls back to the honest sentence, because the
        // site has not seen the certificate and must not imply that it has.
        if ($state === 'certified' && !$evidenceConfirmed) {
            return 'Halal certification claimed by the provider. Not yet confirmed by NearbyPost.';
        }

        return self::HALAL_STATES[$state] ?? self::HALAL_STATES['unspecified'];
    }

    /**
     * Phrases a food listing may not contain, whatever the halal field says.
     *
     * A provider who selects "Muslim-owned" and then writes "100% HALAL
     * CERTIFIED" in the description has made the same claim by the back door,
     * and the field would not catch it.
     */
    private const FORBIDDEN_WITHOUT_CERTIFICATE = [
        'halal certified', 'halal-certified', 'certified halal',
        'jakim certified', 'jakim approved', 'sijil halal', 'halal jakim',
    ];

    /**
     * @return list<string>  the phrases found, empty when the text is clean
     */
    public static function uncertifiedHalalClaims(string $text, string $halalState, bool $evidenceConfirmed): array
    {
        if ($halalState === 'certified' && $evidenceConfirmed) {
            return [];   // they have the certificate; they may say so
        }

        $haystack = mb_strtolower($text);
        $found    = [];

        foreach (self::FORBIDDEN_WITHOUT_CERTIFICATE as $phrase) {
            if (str_contains($haystack, $phrase)) {
                $found[] = $phrase;
            }
        }

        return $found;
    }

    /**
     * The safety sentence shown under a home-cooked listing. Spec §9.3.
     *
     * ⛔ It says what has NOT been done. "Public safety text must explain that
     * NearbyPost has not inspected a home kitchen unless a specific inspection
     * status was actually verified." Silence would let a reader assume the
     * site had looked.
     */
    public static function kitchenNotice(string $operatingMode, bool $inspected = false): ?string
    {
        if ($operatingMode !== 'home_based') {
            return null;
        }

        return $inspected
            ? 'This kitchen has been inspected. The inspection date and authority are shown on the provider page.'
            : 'This food is prepared in a home kitchen. NearbyPost has not inspected it.';
    }

    /**
     * Allergens a provider may declare. Spec §9.1.
     *
     * ⛔ A DECLARATION IS NOT AN ABSENCE. A listing that names no allergens has
     * told the reader nothing, and must never be shown as "allergen free" -
     * which is why there is no such value here and the caller is given wording
     * that says so.
     */
    public const ALLERGENS = [
        'peanuts', 'tree_nuts', 'milk', 'eggs', 'fish', 'shellfish',
        'soy', 'wheat_gluten', 'sesame',
    ];

    /** @param list<string> $declared */
    public static function allergenWording(array $declared): string
    {
        if ($declared === []) {
            return 'The provider has not listed any allergens. Ask before ordering if this matters to you.';
        }

        $names = array_map(fn ($a) => str_replace('_', ' ', $a), array_intersect($declared, self::ALLERGENS));

        return 'The provider says this contains: ' . implode(', ', $names)
             . '. Other ingredients have not been declared.';
    }
}

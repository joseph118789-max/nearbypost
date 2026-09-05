<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;

/**
 * The words a reader is shown about what has been checked. Spec §3.4, §35.
 *
 * ⛔ EVERY LINE IS BUILT FROM A verification_checks ROW, AND SAYS EXACTLY WHAT
 * THAT ROW CHECKED. There is no summary, no score and no overall badge, because
 * the spec forbids all three by name: "NearbyPost Approved", "Fully Verified",
 * "Trusted Merchant" and "Safe Driver" are listed as prohibited (§3.4). A
 * reader who sees one word instead of four facts will read it as a guarantee,
 * and the site has guaranteed nothing.
 *
 * ⛔ AND IT SAYS WHAT IS *NOT* CONFIRMED, IN THE SAME PANEL.
 *
 * Spec §8.3: "Do not use the word `unverified` as the primary label. An
 * expandable verification panel must state precisely what has and has not been
 * confirmed." A home cook who has confirmed her phone but has no company
 * registration is not "unverified" - that word makes her sound like a fraud.
 * She is a person whose phone is confirmed and whose business registration is
 * not, and both halves belong on the page.
 */
class VerificationPanel
{
    /**
     * The wording for each state. Spec §11.5 needs "verified against an
     * official register" and "verified from a document only" to read
     * differently, because they are different claims.
     */
    private const SAYS = [
        'identity' => [
            'verified_register'  => 'Identity confirmed',
            'verified_document'  => 'Identity confirmed from a document',
            'submitted'          => 'Identity document submitted, not yet checked',
            'under_review'       => 'Identity being checked',
            'unable_to_verify'   => 'Identity could not be independently confirmed',
            'rejected'           => 'Identity not confirmed',
            'not_submitted'      => 'Identity not confirmed',
        ],
        'phone' => [
            'verified_register'  => 'Phone confirmed',
            'verified_document'  => 'Phone confirmed',
            'not_submitted'      => 'Phone not confirmed',
        ],
        'business_registration' => [
            'verified_register'  => 'Business registration confirmed',
            'verified_document'  => 'Business registration document submitted, not confirmed against a register',
            'submitted'          => 'Business registration submitted, not yet checked',
            'under_review'       => 'Business registration being checked',
            'unable_to_verify'   => 'Business registration could not be confirmed',
            'expired'            => 'Business registration has lapsed',
            'suspended'          => 'Business registration suspended',
            'rejected'           => 'Business registration not confirmed',
            'not_submitted'      => 'Business registration not confirmed',
        ],
        'professional_credential' => [
            'verified_register'  => 'Professional credential confirmed against the official register',
            'verified_document'  => 'Credential document submitted. Not independently confirmed against an official register',
            'submitted'          => 'Credential submitted, not yet checked',
            'under_review'       => 'Credential being checked',
            'unable_to_verify'   => 'Credential could not be independently confirmed',
            'expired'            => 'Credential has expired',
            'suspended'          => 'Credential suspended',
            'rejected'           => 'Credential not confirmed',
            'not_submitted'      => 'Credential not submitted',
        ],
        'organisation_relationship' => [
            'verified_register'  => 'Relationship with this organisation confirmed',
            'verified_document'  => 'Relationship confirmed from a document',
            'not_submitted'      => 'Relationship with this organisation not confirmed',
        ],
    ];

    /**
     * Which facts are worth stating for each kind of provider, whether or not
     * a row exists. A missing row is itself an answer - "not confirmed" - and
     * omitting it would let absence read as approval.
     */
    private const EXPECTED = [
        'neighbour_provider' => ['identity', 'phone', 'business_registration'],
        'registered_business' => ['identity', 'phone', 'business_registration'],
        'organisation'        => ['business_registration'],
        'professional_firm'   => ['business_registration', 'organisation_relationship'],
    ];

    /**
     * @return list<array{fact: string, says: string, confirmed: bool, checked_at: ?string}>
     */
    public static function forProvider(int $providerId, string $providerKind): array
    {
        $rows = DB::table('verification_checks')
            ->where('subject_type', 'provider')
            ->where('subject_id', $providerId)
            ->get(['verification_type', 'status', 'checked_at'])
            ->keyBy('verification_type');

        $panel = [];

        foreach (self::EXPECTED[$providerKind] ?? self::EXPECTED['neighbour_provider'] as $fact) {
            $row    = $rows->get($fact);
            $status = $row->status ?? 'not_submitted';

            $panel[] = [
                'fact'       => $fact,
                'says'       => self::wording($fact, $status),
                'confirmed'  => in_array($status, ['verified_register', 'verified_document'], true),
                'checked_at' => $row->checked_at ?? null,
            ];
        }

        return $panel;
    }

    /**
     * The one-line label beside a provider's name.
     *
     * ⛔ It names the KIND, never a quality. "Neighbour Provider" is a
     * description of how somebody trades; it is not a rating, and there is
     * deliberately no version of this that gets better as more is confirmed.
     */
    public static function kindLabel(string $providerKind): string
    {
        return match ($providerKind) {
            'neighbour_provider'  => 'Neighbour Provider',
            'registered_business' => 'Business',
            'organisation'        => 'Organisation',
            'professional_firm'   => 'Professional practice',
            default               => 'Provider',
        };
    }

    /** Spec §35: the explanation shown under a Neighbour Provider. */
    public static function explanation(string $providerKind): string
    {
        return $providerKind === 'neighbour_provider'
            ? 'This person offers goods or services on a small or occasional basis. '
              . 'NearbyPost has confirmed the displayed account signals only. '
              . 'Business registration is shown separately when confirmed.'
            : 'NearbyPost has confirmed the facts listed here and nothing beyond them.';
    }

    private static function wording(string $fact, string $status): string
    {
        $forFact = self::SAYS[$fact] ?? [];

        if (isset($forFact[$status])) {
            return $forFact[$status];
        }

        // ⛔ An unknown state must never read as confirmed. A status this class
        // has not been taught is most likely a new one added to the workflow,
        // and the honest reading of "I do not know what this means" is "not
        // confirmed" - never the reverse.
        return ucfirst(str_replace('_', ' ', $fact)) . ' not confirmed';
    }
}

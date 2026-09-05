<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;

/**
 * A professional's place in a firm, clinic or practice. Spec §11.8, §20.12.
 *
 * ⛔⛔ A FIRM DOES NOT CONFER A LICENCE ON ITS STAFF, AND THIS CLASS MUST NEVER
 * LET IT LOOK AS THOUGH IT DOES.
 *
 * Spec §11.8: "Each professional credential is verified independently. A clinic
 * or firm does not automatically confer valid status on every staff member."
 * The temptation is obvious - a well-known practice confirms somebody works
 * there, and it feels like enough. It is not: a receptionist works at a clinic
 * too, and a struck-off doctor may still have a desk. Two separate facts, two
 * separate checks, and describe() below refuses to blur them.
 *
 * The chain the spec draws, and the one this models:
 *
 *   personal account
 *     → professional profile
 *       → credential(s)          verified against the ISSUING BODY
 *       → membership(s)          verified against the FIRM
 *         → organisation profile
 */
class OrganisationMembership
{
    /**
     * The professional says they work somewhere. A claim, and labelled one.
     *
     * @throws CredentialRefused
     */
    public static function claim(int $professionalProfileId, int $providerProfileId, string $roleTitle): int
    {
        $title = trim($roleTitle);

        if (mb_strlen($title) < 2) {
            throw new CredentialRefused('Say what you do there — "Partner", "Associate", "Consultant".');
        }

        $provider = DB::table('provider_profiles')->where('id', $providerProfileId)
            ->whereNull('deleted_at')->first(['id', 'provider_kind']);

        if ($provider === null) {
            throw new CredentialRefused('That organisation is not on the site.');
        }

        $existing = DB::table('professional_organisation_memberships')
            ->where('professional_profile_id', $professionalProfileId)
            ->where('provider_profile_id', $providerProfileId)
            ->where('role_title', $title)
            ->exists();

        if ($existing) {
            throw new CredentialRefused('You have already claimed that role there.');
        }

        $id = (int) DB::table('professional_organisation_memberships')->insertGetId([
            'professional_profile_id' => $professionalProfileId,
            'provider_profile_id'     => $providerProfileId,
            'role_title'              => mb_substr($title, 0, 120),

            // ⛔ Claimed. Nothing better, whoever is claiming it.
            'relationship_status'     => 'claimed',
            'verification_status'     => 'not_submitted',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        DB::table('audit_events')->insert([
            'event'        => 'membership.claimed',
            'subject_type' => 'professional',
            'subject_id'   => $professionalProfileId,
            'after'        => json_encode(['provider_profile_id' => $providerProfileId, 'role' => $title]),
            'created_at'   => now(),
        ]);

        return $id;
    }

    /**
     * Somebody who speaks for the firm confirms it.
     *
     * ⛔ AND IT MUST NOT BE THE SAME PERSON. Confirming your own claim is not a
     * confirmation, it is the claim repeated. Spec §21.4's principle - "provider
     * managers cannot self-verify credentials" - applies here for the same
     * reason.
     *
     * @throws CredentialRefused
     */
    public static function confirm(int $actorUserId, int $membershipId): void
    {
        $m = DB::table('professional_organisation_memberships as m')
            ->join('professional_profiles as p', 'p.id', '=', 'm.professional_profile_id')
            ->where('m.id', $membershipId)
            ->first(['m.id', 'm.provider_profile_id', 'm.professional_profile_id',
                     'm.relationship_status', 'p.user_id as professional_user_id']);

        if ($m === null) {
            throw new CredentialRefused('That membership is no longer here.');
        }

        if ((int) $m->professional_user_id === $actorUserId) {
            throw new CredentialRefused('Somebody else at the organisation has to confirm this, not you.');
        }

        if (!ProviderTeam::may($actorUserId, (int) $m->provider_profile_id, 'members')) {
            throw new CredentialRefused('Only the organisation\'s owner can confirm who works there.');
        }

        DB::table('professional_organisation_memberships')->where('id', $membershipId)->update([
            'relationship_status' => 'confirmed',
            'verification_status' => 'verified_document',
            'verified_at'         => now(),
            'updated_at'          => now(),
        ]);

        DB::table('verification_checks')->updateOrInsert(
            ['subject_type' => 'professional', 'subject_id' => $m->professional_profile_id,
             'verification_type' => 'organisation_relationship'],
            [
                'status'     => 'verified_document',
                'method'     => 'manual',
                'checked_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('audit_events')->insert([
            'event'         => 'membership.confirmed',
            'subject_type'  => 'professional',
            'subject_id'    => $m->professional_profile_id,
            'actor_user_id' => $actorUserId,
            'after'         => json_encode(['membership_id' => $membershipId]),
            'created_at'    => now(),
        ]);
    }

    /** The firm says no. Kept, not deleted: a disputed claim is worth a record. */
    public static function dispute(int $actorUserId, int $membershipId, string $why): void
    {
        $m = DB::table('professional_organisation_memberships')->where('id', $membershipId)
            ->first(['provider_profile_id', 'professional_profile_id']);

        if ($m === null) {
            throw new CredentialRefused('That membership is no longer here.');
        }

        if (!ProviderTeam::may($actorUserId, (int) $m->provider_profile_id, 'members')) {
            throw new CredentialRefused('Only the organisation\'s owner can dispute this.');
        }

        DB::table('professional_organisation_memberships')->where('id', $membershipId)->update([
            'relationship_status' => 'disputed',
            'verification_status' => 'rejected',
            'updated_at'          => now(),
        ]);

        DB::table('audit_events')->insert([
            'event'         => 'membership.disputed',
            'subject_type'  => 'professional',
            'subject_id'    => $m->professional_profile_id,
            'actor_user_id' => $actorUserId,
            'reason'        => mb_substr($why, 0, 400),
            'created_at'    => now(),
        ]);
    }

    /**
     * What a reader is told about somebody's place in a firm.
     *
     * ⛔ THE TWO SENTENCES ARE DELIBERATELY SEPARATE, AND THE SECOND ONE IS
     * ALWAYS PRESENT. Even when both facts are confirmed, the reader is told
     * they were checked separately - because "confirmed at a well-known clinic"
     * is exactly the impression that would otherwise do the work of a licence.
     *
     * @return array{organisation: string, credential: string}
     */
    public static function describe(int $professionalProfileId): array
    {
        $membership = DB::table('professional_organisation_memberships as m')
            ->join('provider_profiles as p', 'p.id', '=', 'm.provider_profile_id')
            ->where('m.professional_profile_id', $professionalProfileId)
            ->orderByRaw("CASE m.relationship_status WHEN 'confirmed' THEN 0 ELSE 1 END")
            ->first(['m.role_title', 'm.relationship_status', 'p.public_name']);

        $organisation = match ($membership->relationship_status ?? null) {
            'confirmed' => sprintf('%s at %s. The organisation has confirmed this.',
                $membership->role_title, $membership->public_name),
            'claimed'   => sprintf('States they are %s at %s. Not confirmed by the organisation.',
                $membership->role_title, $membership->public_name),
            'disputed'  => 'The organisation named does not confirm this relationship.',
            'ended'     => sprintf('Previously %s at %s.', $membership->role_title, $membership->public_name),
            default     => 'No organisation stated.',
        };

        $credential = DB::table('professional_credentials')
            ->where('professional_profile_id', $professionalProfileId)
            ->whereIn('verification_status', ['verified_register', 'verified_document'])
            ->whereNull('deleted_at')
            ->exists()
                ? 'Their professional credential was checked separately. See the credential panel.'
                : '⚠ No professional credential has been confirmed for this person, whatever the organisation says.';

        return ['organisation' => $organisation, 'credential' => $credential];
    }
}

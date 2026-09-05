<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Professional credentials: submitting them, checking them, and the exact words
 * a reader is shown about each. Spec §11.
 *
 * ⛔ THE PUBLIC WORDING FOLLOWS THE STATE, AND THE STATE FOLLOWS WHAT SOMEBODY
 * ACTUALLY DID. Spec §11.5 lists nine states and §35 fixes the wording for the
 * two that matter most - "Professional credential confirmed" against the
 * official register, versus "Credential document submitted. Not independently
 * confirmed against an official register". A reader choosing a surgeon or a
 * lawyer is entitled to know which of those two happened.
 */
class Credentials
{
    /** Spec §11.5. Nine, and no tenth without changing the spec. */
    public const STATES = [
        'not_submitted', 'submitted', 'under_review',
        'verified_register', 'verified_document', 'unable_to_verify',
        'expired', 'suspended', 'rejected',
    ];

    /** The two that permit a regulated service to be advertised. Nothing else does. */
    private const PERMITS_ADVERTISING = ['verified_register', 'verified_document'];

    /**
     * Submit a credential. Spec §11.4.
     *
     * ⛔ NOTE WHAT IS NOT IN THE SIGNATURE: no issue date and no expiry date.
     * Spec §11.4 forbids asking for them, and a parameter that exists gets
     * filled in eventually.
     *
     * @param  array{authority_name: string, registration_number: string, country: string,
     *               region?: ?string, authority_id?: ?int, credential_type?: ?string,
     *               register_url?: ?string, document_media_id?: ?int, declared?: bool}  $input
     *
     * @throws CredentialRefused
     */
    public static function submit(int $profileId, array $input): int
    {
        $authority = trim((string) ($input['authority_name'] ?? ''));
        $number    = trim((string) ($input['registration_number'] ?? ''));
        $country   = strtoupper((string) ($input['country'] ?? ''));

        if ($authority === '' || $number === '' || $country === '') {
            throw new CredentialRefused('Name the body you are registered with, your registration number, and where you may practise.');
        }

        // Spec §11.7: the declarations are the professional's own undertaking,
        // and without them the site is publishing a claim nobody stands behind.
        if (empty($input['declared'])) {
            throw new CredentialRefused('Confirm you are currently authorised to provide these services.');
        }

        $key = self::numberKey($number);

        // ⛔ Someone else already holds this. Not refused outright - a typing
        // mistake looks exactly like an impersonation from here - but it goes
        // nowhere near "verified" without a person.
        $clash = DB::table('professional_credentials')
            ->where('jurisdiction_country_code', $country)
            ->whereRaw('lower(authority_name) = ?', [mb_strtolower($authority)])
            ->where('registration_number_key', $key)
            ->whereIn('verification_status', self::PERMITS_ADVERTISING)
            ->where('professional_profile_id', '!=', $profileId)
            ->exists();

        $id = (int) DB::table('professional_credentials')->insertGetId([
            'professional_profile_id' => $profileId,
            'authority_id'            => $input['authority_id'] ?? null,
            'jurisdiction_country_code' => $country,
            'jurisdiction_region_code'  => $input['region'] ?? null,
            'authority_name'          => mb_substr($authority, 0, 200),
            'credential_type'         => isset($input['credential_type']) ? mb_substr((string) $input['credential_type'], 0, 60) : null,
            'registration_number'     => mb_substr($number, 0, 80),
            'registration_number_key' => $key,
            'official_register_url'   => $input['register_url'] ?? null,
            'document_media_id'       => $input['document_media_id'] ?? null,

            // Submitted. Never anything better, whatever was uploaded.
            'verification_status'     => $clash ? 'under_review' : 'submitted',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        DB::table('audit_events')->insert([
            'event'        => 'credential.submitted',
            'subject_type' => 'professional',
            'subject_id'   => $profileId,
            'after'        => json_encode(['credential_id' => $id, 'authority' => $authority,
                                           'already_claimed_by_another' => $clash]),
            'created_at'   => now(),
        ]);

        return $id;
    }

    /**
     * A moderator's decision. Spec §11.5, §21.4.
     *
     * ⛔ ONLY A MODERATOR REACHES THIS, AND THE METHOD IS RECORDED. Spec §21.4:
     * "Provider managers cannot self-verify credentials." The method is not
     * optional because the public wording depends on it.
     *
     * @throws CredentialRefused
     */
    public static function decide(int $credentialId, string $status, string $method, ?int $adminId, ?string $note = null): void
    {
        if (!in_array($status, self::STATES, true)) {
            throw new CredentialRefused('That is not a credential state this site has.');
        }

        if (in_array($status, self::PERMITS_ADVERTISING, true) && !in_array($method, ['official_register', 'document', 'api_lookup'], true)) {
            throw new CredentialRefused('Say how it was checked. The public wording depends on it.');
        }

        $credential = DB::table('professional_credentials')->where('id', $credentialId)
            ->first(['id', 'professional_profile_id', 'verification_status']);

        if ($credential === null) {
            throw new CredentialRefused('That credential is no longer here.');
        }

        DB::transaction(function () use ($credential, $credentialId, $status, $method, $adminId, $note) {
            DB::table('professional_credentials')->where('id', $credentialId)->update([
                'verification_status'  => $status,
                'verification_method'  => in_array($status, self::PERMITS_ADVERTISING, true) ? $method : null,
                'verified_at'          => in_array($status, self::PERMITS_ADVERTISING, true) ? now() : null,
                'verified_by_admin_id' => $adminId,

                // ⛔ A credential is not confirmed for ever. Spec §20.18 has a
                // next_review_at for exactly this: a practising certificate
                // lapses, and nobody tells us.
                'next_review_at'       => in_array($status, self::PERMITS_ADVERTISING, true) ? now()->addMonths(12) : null,
                'updated_at'           => now(),
            ]);

            // The provider-side record, so one panel can render every fact
            // about a subject without knowing where it came from.
            DB::table('verification_checks')->updateOrInsert(
                ['subject_type' => 'professional', 'subject_id' => $credential->professional_profile_id,
                 'verification_type' => 'professional_credential'],
                [
                    'status'     => $status,
                    'method'     => in_array($status, self::PERMITS_ADVERTISING, true) ? $method : null,
                    'checked_at' => in_array($status, self::PERMITS_ADVERTISING, true) ? now() : null,
                    'checked_by_admin_id' => $adminId,
                    'notes'      => $note,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('audit_events')->insert([
                'event'          => 'credential.' . $status,
                'subject_type'   => 'professional',
                'subject_id'     => $credential->professional_profile_id,
                'actor_admin_id' => $adminId,
                'before'         => json_encode(['status' => $credential->verification_status]),
                'after'          => json_encode(['status' => $status, 'method' => $method]),
                'reason'         => $note,
                'created_at'     => now(),
            ]);
        });
    }

    /**
     * The words a reader sees. Spec §35.
     *
     * @return array{says: string, detail: ?string, permits_advertising: bool}
     */
    public static function wording(int $credentialId): array
    {
        $c = DB::table('professional_credentials')->where('id', $credentialId)
            ->first(['verification_status', 'verification_method', 'authority_name',
                     'jurisdiction_country_code', 'jurisdiction_region_code', 'verified_at']);

        if ($c === null) {
            return ['says' => 'Credential not submitted', 'detail' => null, 'permits_advertising' => false];
        }

        $where = $c->jurisdiction_region_code
            ? $c->jurisdiction_region_code . ', ' . $c->jurisdiction_country_code
            : $c->jurisdiction_country_code;

        $says = match ($c->verification_status) {
            // ⛔ These two sentences are the point of the whole class.
            'verified_register' => 'Professional credential confirmed',
            'verified_document' => 'Credential document submitted',

            'submitted'        => 'Credential submitted, not yet checked',
            'under_review'     => 'Credential being checked',
            'unable_to_verify' => 'Unable to independently confirm this credential',
            'expired'          => 'This credential has expired',
            'suspended'        => 'This credential is suspended',
            'rejected'         => 'Credential not confirmed',
            default            => 'Credential not submitted',
        };

        $detail = match ($c->verification_status) {
            'verified_register' => sprintf('Issuing authority: %s · Jurisdiction: %s · Checked by NearbyPost: %s',
                $c->authority_name, $where, \Carbon\Carbon::parse($c->verified_at)->format('F Y')),

            // ⛔ The disclaimer is part of the sentence, not a footnote. A
            // document is not a register check and the reader must be told so
            // in the same breath (§35).
            'verified_document' => 'Not independently confirmed against an official register. Issuing authority as stated: '
                                   . $c->authority_name,

            default => null,
        };

        return [
            'says'   => $says,
            'detail' => $detail,
            'permits_advertising' => in_array($c->verification_status, self::PERMITS_ADVERTISING, true),
        ];
    }

    /**
     * May this profile advertise under a protected title? Spec §11.9.
     *
     * ⛔ THE TITLE IS BLOCKED, AND NOTHING IS SILENTLY REWRITTEN. Spec §11.9:
     * "never automatically rewrite a protected professional title". Offering a
     * truthful alternative is allowed; substituting one behind the person's
     * back is putting words in their mouth about their own qualifications.
     *
     * @return ?string  null when allowed, otherwise why not
     */
    public static function blocksTitle(int $profileId, string $title, string $country): ?string
    {
        $protected = DB::table('professional_authorities')
            ->where('country_code', strtoupper($country))
            ->where('active', true)
            ->get(['name', 'protected_titles']);

        $wanted = mb_strtolower(trim($title));

        foreach ($protected as $authority) {
            $titles = json_decode((string) $authority->protected_titles, true) ?: [];

            foreach ($titles as $reserved) {
                if (!str_contains($wanted, mb_strtolower($reserved))) {
                    continue;
                }

                $holds = DB::table('professional_credentials')
                    ->where('professional_profile_id', $profileId)
                    ->where('jurisdiction_country_code', strtoupper($country))
                    ->whereRaw('lower(authority_name) = ?', [mb_strtolower($authority->name)])
                    ->whereIn('verification_status', self::PERMITS_ADVERTISING)
                    ->exists();

                if (!$holds) {
                    return sprintf('"%s" is reserved for people registered with %s. '
                                 . 'Confirm that registration first, or describe the service without the title.',
                                 $reserved, $authority->name);
                }
            }
        }

        return null;
    }

    /** Spaces, dashes and case are typing, not identity. */
    private static function numberKey(string $number): string
    {
        return mb_substr(mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $number) ?: ''), 0, 80);
    }

    /** A new professional profile. Spec §11.3. */
    public static function createProfile(int $userId, array $input): int
    {
        $name = trim((string) ($input['public_name'] ?? ''));

        if (mb_strlen($name) < 3) {
            throw new CredentialRefused('Give the name you practise under.');
        }

        if (DB::table('professional_profiles')->where('user_id', $userId)->exists()) {
            throw new CredentialRefused('You already have a professional profile. Add a credential to it instead.');
        }

        $base = Str::slug($name) ?: 'professional';
        $slug = $base;
        $n    = 1;

        while (DB::table('professional_profiles')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return (int) DB::table('professional_profiles')->insertGetId([
            'public_uuid'              => (string) Str::uuid(),
            'slug'                     => mb_substr($slug, 0, 160),
            'user_id'                  => $userId,
            'public_professional_name' => mb_substr($name, 0, 160),
            'legal_name'               => isset($input['legal_name']) ? mb_substr((string) $input['legal_name'], 0, 200) : null,
            'biography'                => isset($input['biography']) ? mb_substr((string) $input['biography'], 0, 4000) : null,
            'country_of_residence'     => isset($input['country']) ? strtoupper(substr((string) $input['country'], 0, 2)) : null,
            'professional_email'       => $input['email'] ?? null,

            // ⛔ Draft until a credential is confirmed. A professional profile
            // that is live before anything has been checked is an advertisement
            // for an unverified regulated service.
            'publication_status'       => 'draft',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }
}

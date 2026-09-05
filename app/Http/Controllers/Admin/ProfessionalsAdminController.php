<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\CredentialRefused;
use App\Services\Marketplace\Credentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The credential review queue. Spec §25.1, §11.5.
 *
 * ⛔ THIS SCREEN EXISTS TO MAKE ONE DISTINCTION EASY TO GET RIGHT: did the
 * moderator check an official register, or did they look at a document?
 *
 * Spec §11.5 and §35 give those two outcomes different public sentences, and a
 * reader choosing a surgeon relies on the difference. So the two are separate
 * buttons with separate wording, never one "Approve" that quietly picks a
 * method - which is what a busy queue would produce if it could.
 */
class ProfessionalsAdminController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.marketplace-professionals', [
            'waiting'    => $this->waiting(),
            'due'        => $this->dueForRecheck(),
            'counts'     => $this->counts(),
            'authorities' => DB::table('professional_authorities')
                ->where('active', true)->orderBy('country_code')->orderBy('name')
                ->get(['id', 'country_code', 'profession_family', 'name', 'register_url'])
                ->map(fn ($r) => (array) $r)->all(),
        ]);
    }

    private function counts(): array
    {
        $all = DB::table('professional_credentials')->whereNull('deleted_at');

        return [
            'waiting'   => (clone $all)->whereIn('verification_status', ['submitted', 'under_review'])->count(),
            'confirmed' => (clone $all)->where('verification_status', 'verified_register')->count(),
            'document'  => (clone $all)->where('verification_status', 'verified_document')->count(),
            'refused'   => (clone $all)->whereIn('verification_status', ['rejected', 'unable_to_verify'])->count(),
            'lapsed'    => (clone $all)->whereIn('verification_status', ['expired', 'suspended'])->count(),
            'due'       => (clone $all)->whereNotNull('next_review_at')
                               ->where('next_review_at', '<=', now())->count(),
            'profiles'  => DB::table('professional_profiles')->whereNull('deleted_at')->count(),
        ];
    }

    /** Oldest first: the person who has waited longest is seen first. */
    private function waiting(): array
    {
        return DB::table('professional_credentials as c')
            ->join('professional_profiles as p', 'p.id', '=', 'c.professional_profile_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('professional_authorities as a', 'a.id', '=', 'c.authority_id')
            ->whereIn('c.verification_status', ['submitted', 'under_review'])
            ->whereNull('c.deleted_at')
            ->orderBy('c.created_at')
            ->limit(50)
            ->get([
                'c.id', 'c.authority_name', 'c.registration_number', 'c.registration_number_key',
                'c.credential_type', 'c.jurisdiction_country_code', 'c.jurisdiction_region_code',
                'c.official_register_url', 'c.document_media_id', 'c.verification_status', 'c.created_at',
                'p.id as profile_id', 'p.public_professional_name', 'p.legal_name', 'p.slug',
                'u.username', 'a.register_url as authority_register_url',
            ])
            ->map(function ($r) {
                $row = (array) $r;

                // ⛔ Somebody else already holds this number. The submitter is
                // not accused of anything - it is far more often a typing
                // mistake - but the moderator must see it before deciding.
                $row['also_claimed_by'] = DB::table('professional_credentials as o')
                    ->join('professional_profiles as op', 'op.id', '=', 'o.professional_profile_id')
                    ->where('o.registration_number_key', $r->registration_number_key)
                    ->whereRaw('lower(o.authority_name) = ?', [mb_strtolower($r->authority_name)])
                    ->where('o.id', '!=', $r->id)
                    ->whereNull('o.deleted_at')
                    ->pluck('op.public_professional_name')
                    ->all();

                return $row;
            })
            ->all();
    }

    /**
     * Credentials whose year is up. Spec §20.18's next_review_at.
     *
     * ⛔ A practising certificate lapses and nobody writes to tell us. Without
     * this list, "confirmed" would mean "was confirmed once, at some point" -
     * which is a claim the site would be making on the professional's behalf
     * long after it stopped being true.
     */
    private function dueForRecheck(): array
    {
        return DB::table('professional_credentials as c')
            ->join('professional_profiles as p', 'p.id', '=', 'c.professional_profile_id')
            ->whereNotNull('c.next_review_at')
            ->where('c.next_review_at', '<=', now())
            ->whereIn('c.verification_status', ['verified_register', 'verified_document'])
            ->whereNull('c.deleted_at')
            ->orderBy('c.next_review_at')
            ->limit(50)
            ->get(['c.id', 'c.authority_name', 'c.registration_number', 'c.verification_status',
                   'c.verified_at', 'c.next_review_at', 'p.public_professional_name'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /* ------------------------------------------------------------- actions */

    /** Checked against the issuing body's own register. The strongest thing the site can say. */
    public function confirmFromRegister(Request $request, int $id): RedirectResponse
    {
        return $this->decide($id, 'verified_register', 'official_register', $request->input('note'),
            'Confirmed against the register.');
    }

    /** A document was seen. Weaker, and the public wording says so. */
    public function confirmFromDocument(Request $request, int $id): RedirectResponse
    {
        return $this->decide($id, 'verified_document', 'document', $request->input('note'),
            'Recorded as document-only.');
    }

    /**
     * Tried and could not. Spec §11.5 keeps this apart from "rejected" because
     * they mean different things to the person: one says we failed to check,
     * the other says we checked and it was not good.
     */
    public function unableToVerify(Request $request, int $id): RedirectResponse
    {
        $note = trim((string) $request->input('note'));

        if ($note === '') {
            return back()->withErrors(['note' => 'Say what you tried, so the next person does not repeat it.']);
        }

        return $this->decide($id, 'unable_to_verify', 'manual', $note, 'Recorded as not independently confirmable.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $note = trim((string) $request->input('note'));

        if ($note === '') {
            return back()->withErrors(['note' => 'Say why. The professional is told the reason.']);
        }

        return $this->decide($id, 'rejected', 'manual', $note, 'Turned down.');
    }

    public function suspend(Request $request, int $id): RedirectResponse
    {
        $note = trim((string) $request->input('note'));

        if ($note === '') {
            return back()->withErrors(['note' => 'Say why it is being suspended.']);
        }

        return $this->decide($id, 'suspended', 'manual', $note, 'Suspended.');
    }

    private function decide(int $id, string $status, string $method, ?string $note, string $message): RedirectResponse
    {
        try {
            Credentials::decide($id, $status, $method, Auth::guard('admin')->id(), $note ? mb_substr($note, 0, 2000) : null);
        } catch (CredentialRefused $e) {
            return back()->withErrors(['credential' => $e->getMessage()]);
        }

        // ⛔ A professional profile goes live only when something has actually
        // been confirmed, and comes back down the moment nothing is.
        $this->syncProfileVisibility($id);

        return back()->with('status', $message);
    }

    /**
     * ⛔ THE PROFILE FOLLOWS THE CREDENTIALS, IN BOTH DIRECTIONS.
     *
     * Publishing on confirmation is obvious. Un-publishing when the last good
     * credential is suspended is the half that gets forgotten, and it is the
     * half that matters: a suspended solicitor whose profile stays up is the
     * site advertising a regulated service on their behalf.
     */
    private function syncProfileVisibility(int $credentialId): void
    {
        $profileId = DB::table('professional_credentials')->where('id', $credentialId)
            ->value('professional_profile_id');

        if ($profileId === null) {
            return;
        }

        $stillGood = DB::table('professional_credentials')
            ->where('professional_profile_id', $profileId)
            ->whereIn('verification_status', ['verified_register', 'verified_document'])
            ->whereNull('deleted_at')
            ->exists();

        $was = DB::table('professional_profiles')->where('id', $profileId)->value('publication_status');
        $now = $stillGood ? 'published' : ($was === 'published' ? 'suspended' : $was);

        if ($now === $was) {
            return;
        }

        DB::table('professional_profiles')->where('id', $profileId)
            ->update(['publication_status' => $now, 'updated_at' => now()]);

        DB::table('audit_events')->insert([
            'event'          => 'professional.' . ($now === 'published' ? 'published' : 'withdrawn'),
            'subject_type'   => 'professional',
            'subject_id'     => $profileId,
            'actor_admin_id' => Auth::guard('admin')->id(),
            'before'         => json_encode(['publication_status' => $was]),
            'after'          => json_encode(['publication_status' => $now]),
            'reason'         => $stillGood ? 'a credential was confirmed' : 'no confirmed credential remains',
            'created_at'     => now(),
        ]);
    }
}

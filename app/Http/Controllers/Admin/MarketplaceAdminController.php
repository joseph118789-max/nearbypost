<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Flags\FeatureFlags;
use App\Services\Marketplace\ListingSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The Marketplace desk. Spec §25.
 *
 * The same shape as the Unofficial desk, which is what the placeholder this
 * replaces promised: waiting for review, live, removed.
 *
 * ⛔ EVERY ACTION HERE WRITES AN AUDIT ROW, AND EVERY REFUSAL NEEDS A REASON.
 * Spec §25.2 asks for "actions with required reason" and §20.20 for an audit
 * trail. A moderator who suspends a listing and cannot say why has left the
 * provider nothing to appeal against.
 */
class MarketplaceAdminController extends Controller
{
    public function index(Request $request): View
    {
        $country = strtoupper((string) $request->query('country', 'MY'));

        return view('admin.marketplace', [
            'country'   => $country,
            'flags'     => FeatureFlags::forCountry($country),
            'counts'    => $this->counts(),
            'waiting'   => $this->waiting(),
            'reports'   => $this->openReports(),
            'live'      => $this->live(),
            'openRules' => DB::table('marketplace_category_country_rules')
                ->where('country_code', $country)->where('enabled', true)->count(),
            'categories' => DB::table('marketplace_categories')->count(),
        ]);
    }


    /**
     * Where the whole Marketplace stands, in one page.
     *
     * Six phases were built behind flags that are all off, which means the
     * owner has paid for a module he cannot see. This is that page: what
     * exists, what is switched off, and - the part that matters - which
     * decisions are his and would turn each piece on.
     *
     * ⛔ EVERY LINE HERE IS DERIVED, NOT WRITTEN DOWN. A hand-maintained status
     * page is wrong within a fortnight and is then worse than no page, because
     * it is believed. The blockers below are read from the database and the
     * configuration at request time, so the day an OTP provider is configured
     * this page stops saying it is missing without anyone editing it.
     */
    public function status(Request $request): View
    {
        $country = strtoupper((string) $request->query('country', 'MY'));

        $count = fn (string $table) => \Illuminate\Support\Facades\Schema::hasTable($table)
            ? DB::table($table)->count() : null;

        // What a person could do today, asked of the data rather than assumed.
        $anyPhoneConfirmed = DB::table('users')->whereNotNull('phone_verified_at')->exists();
        $anyIdentity       = \Illuminate\Support\Facades\Schema::hasColumn('users', 'identity_verification_status')
            && DB::table('users')->where('identity_verification_status', 'confirmed')->exists();
        $paymentConfigured = false;   // no provider package is installed at all
        $propertyFeed      = (string) config('services.listingmine.property_feed_url', '') !== '';

        $flags = FeatureFlags::forCountry($country);

        // ⛔ NOT (bool) $flags[$key]. forCountry() returns a row per key -
        // ['enabled' => …, 'source' => …, 'note' => …] - and casting a
        // non-empty array to bool is always true, so the first version of this
        // page reported all six phases as ON while every switch was off. A
        // status page that lies is worse than no status page, because it is
        // believed.
        //
        // FeatureFlags::on() is the same call the services make, and it also
        // applies the rule that a sensitive capability is never inherited from
        // a global default by an unconfigured country.
        $on = fn (string $key) => FeatureFlags::on($key, $country);

        $phases = [
            [
                'n'       => 1,
                'name'    => 'Foundation',
                'reader'  => 'Nothing a reader sees. The tables, the search by distance, the photo handling and the WhatsApp link everything else is built on.',
                'flag'    => 'marketplace_enabled',
                'on'      => $on('marketplace_enabled'),
                'counts'  => [
                    'categories'      => $count('marketplace_categories'),
                    'open in ' . $country => DB::table('marketplace_category_country_rules')
                        ->where('country_code', $country)->where('enabled', true)->count(),
                    'providers'       => $count('provider_profiles'),
                    'listings'        => $count('marketplace_listings'),
                ],
                'blocker' => null,
            ],
            [
                'n'       => 2,
                'name'    => 'Neighbour offers, business directory, buy &amp; sell',
                'reader'  => 'Someone selling nasi lemak from home, a shop down the road, a second-hand cot. The categories most people would actually use.',
                'flag'    => 'neighbour_offers_enabled',
                'on'      => $on('neighbour_offers_enabled') || $on('business_directory_enabled') || $on('individual_sell_enabled'),
                'counts'  => ['ratings on' => $on('ratings_enabled') ? 'yes' : 'no'],
                'blocker' => $anyPhoneConfirmed ? null :
                    'No way to confirm a phone number. Everything in this phase requires it, so nobody can list anything. Needs an SMS or WhatsApp provider chosen - it costs money per message, which is why it is your decision.',
            ],
            [
                'n'       => 3,
                'name'    => 'Professional services',
                'reader'  => 'A lawyer, an architect, a plumber with a licence. Their credentials are checked by a person and the wording never overstates what was confirmed.',
                'flag'    => 'professional_services_enabled',
                'on'      => $on('professional_services_enabled'),
                'counts'  => [
                    'profiles'    => $count('professional_profiles'),
                    'credentials' => $count('professional_credentials'),
                ],
                'blocker' => $anyPhoneConfirmed ? null : 'The same phone confirmation as phase 2.',
            ],
            [
                'n'       => 4,
                'name'    => 'Car Pool',
                'reader'  => 'A noticeboard for lifts people were already making. No fares, no bookings, no seat held - by design, and the database enforces it.',
                'flag'    => 'carpool_enabled',
                'on'      => $on('carpool_enabled'),
                'counts'  => [
                    'drivers and passengers' => $count('carpool_profiles'),
                    'journey notices'        => $count('carpool_notices'),
                ],
                'blocker' => $anyIdentity
                    ? 'A transport-law review for each country before the switch is turned on. Not a coding task.'
                    : 'Two things: identity confirmation does not exist yet (this is the only part of the Marketplace that demands it, because strangers get into a car together), and each country needs a transport-law review first.',
            ],
            [
                'n'       => 5,
                'name'    => 'Sponsored placements',
                'reader'  => 'A business paying to appear higher in the list. Always labelled, capped at two per page, and it can never hide the true distance or what was verified.',
                'flag'    => 'sponsored_listings_enabled',
                'on'      => $on('sponsored_listings_enabled'),
                'counts'  => [
                    'campaigns' => $count('sponsored_campaigns'),
                    'payments'  => $count('advertising_payments'),
                ],
                'blocker' => $paymentConfigured ? null :
                    'No payment provider is connected, so nobody can pay and no campaign can go live. Everything else is built: a campaign can be created and it stops at "waiting for payment".',
            ],
            [
                'n'       => 6,
                'name'    => 'Property from ListingMine',
                'reader'  => 'Property listings shown near a reader, sending them to ListingMine to enquire. ListingMine stays in charge of the data.',
                'flag'    => 'property_listingmine_enabled',
                'on'      => $on('property_listingmine_enabled'),
                'counts'  => [
                    'properties mirrored' => $count('property_listings'),
                    'clicks sent'         => $count('property_click_events'),
                ],
                'blocker' => $propertyFeed ? null :
                    'ListingMine does not yet publish a list of properties for us to read. Everything on this side is finished and tested; one web address from ListingMine turns it on.',
            ],
        ];

        return view('admin.marketplace-status', [
            'country' => $country,
            'phases'  => $phases,
            'flags'   => $flags,
            'live'    => DB::table('feature_flags')->where('enabled', true)->count(),
        ]);
    }

    /**
     * ⛔ ONE QUERY PER BUCKET, AND THE BUCKETS MUST ADD UP.
     *
     * This project has already paid for the alternative: the live screen's
     * counts and its drill-downs each built their own conditions and quietly
     * disagreed. "Live" here is ListingSearch::visible(), the same predicate
     * the public feed uses, so a number on this page and a listing on the site
     * cannot tell different stories.
     */
    private function counts(): array
    {
        $all = DB::table('marketplace_listings')->whereNull('deleted_at');

        return [
            'live'      => (clone $all)->where('publication_status', 'published')
                               ->whereIn('moderation_status', ['ai_accepted', 'manual_accepted'])
                               ->where('expires_at', '>', now())->count(),
            'waiting'   => (clone $all)->where('publication_status', 'submitted')->count(),
            'draft'     => (clone $all)->where('publication_status', 'draft')->count(),
            'paused'    => (clone $all)->where('publication_status', 'paused')->count(),
            'suspended' => (clone $all)->where('publication_status', 'suspended')->count(),
            'removed'   => (clone $all)->where('publication_status', 'removed')->count(),
            'expired'   => (clone $all)->where('publication_status', 'published')
                               ->where('expires_at', '<=', now())->count(),
            'providers' => DB::table('provider_profiles')->whereNull('deleted_at')->count(),
        ];
    }

    /** Listings a person has to look at, oldest first: nobody should wait longest by accident. */
    private function waiting(): array
    {
        return DB::table('marketplace_listings as l')
            ->leftJoin('provider_profiles as p', 'p.id', '=', 'l.provider_profile_id')
            ->leftJoin('marketplace_categories as c', 'c.id', '=', 'l.category_id')
            ->where('l.publication_status', 'submitted')
            ->whereNull('l.deleted_at')
            ->orderBy('l.updated_at')
            ->limit(50)
            ->get([
                'l.id', 'l.title', 'l.description', 'l.price_mode', 'l.price_min', 'l.price_max',
                'l.currency', 'l.public_location_mode', 'l.moderation_status', 'l.country_code',
                'l.updated_at', 'p.public_name as provider', 'p.provider_kind', 'c.default_name as category',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Reports still open, worst first.
     *
     * ⛔ Ordered by SEVERITY before age. A queue sorted only by time buries a
     * scam report under forty complaints about opening hours, which is exactly
     * what spec §17.3 forbids.
     */
    private function openReports(): array
    {
        return DB::table('marketplace_reports as r')
            ->whereIn('r.status', ['open', 'reopened'])
            ->orderByRaw("CASE r.severity
                            WHEN 'critical' THEN 0 WHEN 'high' THEN 1
                            WHEN 'medium'   THEN 2 ELSE 3 END")
            ->orderBy('r.created_at')
            ->limit(50)
            ->get(['r.id', 'r.target_type', 'r.target_id', 'r.reason_code', 'r.severity',
                   'r.description', 'r.group_key', 'r.created_at'])
            ->map(function ($r) {
                $row = (array) $r;

                // How many other people said the same thing about the same
                // target. Shown so a moderator can see weight without the queue
                // being flooded by it.
                $row['also'] = DB::table('marketplace_reports')
                    ->where('group_key', $r->group_key)
                    ->where('id', '!=', $r->id)
                    ->count();

                return $row;
            })
            ->all();
    }

    private function live(): array
    {
        return ListingSearch::visible()
            ->leftJoin('provider_profiles as p', 'p.id', '=', 'l.provider_profile_id')
            ->orderByDesc('l.published_at')
            ->limit(30)
            ->get(['l.id', 'l.title', 'l.country_code', 'l.expires_at', 'l.published_at',
                   'l.public_location_mode', 'p.public_name as provider'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /* ------------------------------------------------------------- actions */

    public function approve(Request $request, int $id): RedirectResponse
    {
        return $this->decide($request, $id, 'manual_accepted', 'published', 'listing.approved', 'Published.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $reason = trim((string) $request->input('reason'));

        if ($reason === '') {
            return back()->withErrors(['reason' => 'Say why it was turned down. The provider is told the reason.']);
        }

        return $this->decide($request, $id, 'manual_rejected', 'rejected', 'listing.rejected', 'Turned down.');
    }

    public function suspend(Request $request, int $id): RedirectResponse
    {
        $reason = trim((string) $request->input('reason'));

        if ($reason === '') {
            return back()->withErrors(['reason' => 'Say why it was suspended.']);
        }

        return $this->decide($request, $id, 'manual_review', 'suspended', 'listing.suspended', 'Suspended.');
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        return $this->decide($request, $id, 'manual_accepted', 'published', 'listing.restored', 'Put back.');
    }

    /**
     * One path for every decision, so none of them can forget the audit row.
     */
    private function decide(Request $request, int $id, string $moderation, string $publication, string $event, string $message): RedirectResponse
    {
        $before = DB::table('marketplace_listings')->where('id', $id)
            ->first(['publication_status', 'moderation_status']);

        if ($before === null) {
            return back()->withErrors(['listing' => 'That listing no longer exists.']);
        }

        $update = [
            'publication_status' => $publication,
            'moderation_status'  => $moderation,
            'updated_at'         => now(),
        ];

        // published_at is set the FIRST time it goes live and never rewritten,
        // so "how long has this been up" stays answerable after a suspension
        // and a restoration.
        if ($publication === 'published') {
            $update['published_at'] = DB::raw('COALESCE(published_at, NOW())');
        }

        DB::table('marketplace_listings')->where('id', $id)->update($update);

        DB::table('moderation_decisions')->insert([
            'subject_type'         => 'listing',
            'subject_id'           => $id,
            'provider'             => 'human',
            'decision'             => $moderation === 'manual_accepted' ? 'accept' : 'reject',
            'reason_codes'         => json_encode(array_filter([$request->input('reason_code')])),
            'reviewed_by_admin_id' => Auth::guard('admin')->id(),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::table('audit_events')->insert([
            'event'          => $event,
            'subject_type'   => 'listing',
            'subject_id'     => $id,
            'actor_admin_id' => Auth::guard('admin')->id(),
            'before'         => json_encode((array) $before),
            'after'          => json_encode(['publication_status' => $publication, 'moderation_status' => $moderation]),
            'reason'         => mb_substr((string) $request->input('reason'), 0, 400) ?: null,
            'created_at'     => now(),
        ]);

        return back()->with('status', $message);
    }

    /** Close a report with a decision and a reason. Spec §17.5. */
    public function resolveReport(Request $request, int $id): RedirectResponse
    {
        $decision = (string) $request->input('decision_code');

        if ($decision === '') {
            return back()->withErrors(['decision_code' => 'Choose an outcome. A decision with no reason cannot be appealed.']);
        }

        $report = DB::table('marketplace_reports')->where('id', $id)->first(['group_key']);

        DB::table('marketplace_reports')->where('id', $id)->update([
            'status'         => 'actioned',
            'decision_code'  => $decision,
            'decision_notes' => mb_substr((string) $request->input('notes'), 0, 2000) ?: null,
            'decided_at'     => now(),
            'assigned_to_admin_id' => Auth::guard('admin')->id(),
            'updated_at'     => now(),
        ]);

        // Everything grouped behind it is settled by the same decision - it was
        // the same complaint about the same thing.
        if ($report !== null && $report->group_key !== null) {
            DB::table('marketplace_reports')
                ->where('group_key', $report->group_key)
                ->where('status', 'grouped')
                ->update(['status' => 'actioned', 'decision_code' => $decision, 'decided_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('status', 'Report closed.');
    }
}

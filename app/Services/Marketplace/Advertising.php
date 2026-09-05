<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plans, campaigns and the money that turns one into the other. Spec 20.21.
 *
 * ⛔ THERE IS NO PAYMENT PROVIDER YET, AND THIS CLASS DOES NOT PRETEND OTHERWISE.
 *
 * Nothing here charges anybody. `recordPayment()` writes down what a billing
 * company has already told us it did; it never initiates anything. Until a
 * provider is chosen and wired up, every campaign a person could create stops
 * at `pending_payment`, which is the honest state - the alternative would be
 * campaigns going live having settled nothing.
 *
 * The one way past that is `waive()`: an admin deliberately comping a campaign,
 * recorded in the audit log with a reason. That is a real business case (a
 * house campaign, an apology, a launch partner) and it is also what makes the
 * placement machinery testable before a payment provider exists.
 */
class Advertising
{
    /** Spec 15.4. The word a reader sees, and the default nobody has to remember. */
    public const DEFAULT_LABEL = 'Sponsored';

    /**
     * A campaign may run for at most this long without being renewed.
     *
     * Not a business rule so much as a safety catch: an open-ended campaign is
     * one nobody revisits, and a paid slot that outlives the arrangement behind
     * it is the kind of thing found a year later.
     */
    private const MAX_RUN_DAYS = 366;

    /* ------------------------------------------------------------ campaigns */

    /**
     * Draft a campaign. It cannot run yet - see the class note.
     *
     * @param  array{listing_id?: ?int, category_id?: ?int, lat?: ?float, lng?: ?float,
     *               radius_metres?: ?int, starts_at: string, ends_at: string,
     *               budget?: float, currency?: string, label?: ?string}  $c
     *
     * @throws AdvertisingRefused
     */
    public static function createCampaign(int $providerId, string $country, array $c): int
    {
        $country = strtoupper($country);

        // ⛔ Checked here, not only in a controller. sponsored_listings_enabled
        // is one of the flags a country must opt into BY NAME (it takes money
        // and carries advertising-law exposure), so it can never arrive
        // somewhere by inheriting a global default.
        if (FeatureFlags::off('sponsored_listings_enabled', $country)) {
            throw new AdvertisingRefused('Sponsored placements are not available in ' . $country . '.');
        }

        $starts = \Carbon\Carbon::parse($c['starts_at']);
        $ends   = \Carbon\Carbon::parse($c['ends_at']);

        if ($ends->lessThanOrEqualTo($starts)) {
            throw new AdvertisingRefused('The campaign must end after it starts.');
        }

        if ($starts->diffInDays($ends) > self::MAX_RUN_DAYS) {
            throw new AdvertisingRefused('A campaign may run for at most a year before it is renewed.');
        }

        $listingId = isset($c['listing_id']) ? (int) $c['listing_id'] : null;

        if ($listingId !== null) {
            // ⛔ You may only sponsor something you own. Otherwise a provider
            // could buy placement pointing at a rival's listing, or at one
            // that has since been suspended.
            $owns = DB::table('marketplace_listings')
                ->where('id', $listingId)
                ->where('provider_profile_id', $providerId)
                ->whereNull('deleted_at')
                ->exists();

            if (!$owns) {
                throw new AdvertisingRefused('That listing does not belong to this provider.');
            }
        }

        $radius = isset($c['radius_metres']) ? (int) $c['radius_metres'] : null;
        $lat    = isset($c['lat']) ? (float) $c['lat'] : null;
        $lng    = isset($c['lng']) ? (float) $c['lng'] : null;

        if ($radius !== null && ($lat === null || $lng === null)) {
            throw new AdvertisingRefused('A campaign with a radius needs a centre point.');
        }

        $label = trim((string) ($c['label'] ?? self::DEFAULT_LABEL));

        if ($label === '') {
            throw new AdvertisingRefused('A sponsored placement must carry a label.');
        }

        $id = (int) DB::table('sponsored_campaigns')->insertGetId([
            'public_uuid'         => (string) Str::uuid(),
            'provider_profile_id' => $providerId,
            'listing_id'          => $listingId,
            'category_id'         => isset($c['category_id']) ? (int) $c['category_id'] : null,
            'country_code'        => $country,
            'centre_lat'          => $lat,
            'centre_lng'          => $lng,
            'radius_metres'       => $radius,
            'starts_at'           => $starts,
            'ends_at'             => $ends,
            'budget'              => (float) ($c['budget'] ?? 0),
            'currency'            => strtoupper((string) ($c['currency'] ?? 'MYR')),

            // Never 'active'. Money first, and there is no way to pay yet.
            'status'              => 'pending_payment',
            'payment_status'      => 'unpaid',
            'disclosure_label'    => $label,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        self::audit('advertising.campaign_created', $providerId, ['campaign_id' => $id]);

        return $id;
    }

    /**
     * Put a settled campaign in front of readers.
     *
     * @throws AdvertisingRefused
     */
    public static function activate(int $campaignId): void
    {
        $c = DB::table('sponsored_campaigns')->where('id', $campaignId)->first();

        if ($c === null) {
            throw new AdvertisingRefused('No such campaign.');
        }

        if (FeatureFlags::off('sponsored_listings_enabled', $c->country_code)) {
            throw new AdvertisingRefused('Sponsored placements are not available in ' . $c->country_code . '.');
        }

        // The database says this too (ad_campaign_active_needs_settlement). It
        // is said twice on purpose: the constraint is the guarantee, this is
        // the sentence a person can act on.
        if (!in_array($c->payment_status, ['paid', 'waived'], true)) {
            throw new AdvertisingRefused('This campaign has not been paid for.');
        }

        DB::table('sponsored_campaigns')->where('id', $campaignId)
            ->update(['status' => 'active', 'updated_at' => now()]);

        self::audit('advertising.campaign_activated', (int) $c->provider_profile_id,
            ['campaign_id' => $campaignId]);
    }

    /**
     * Comp a campaign. Deliberate, attributed and audited.
     *
     * @throws AdvertisingRefused
     */
    public static function waive(int $campaignId, int $adminId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new AdvertisingRefused('Say why this campaign is being given away.');
        }

        $c = DB::table('sponsored_campaigns')->where('id', $campaignId)->first(['provider_profile_id']);

        if ($c === null) {
            throw new AdvertisingRefused('No such campaign.');
        }

        DB::table('sponsored_campaigns')->where('id', $campaignId)
            ->update(['payment_status' => 'waived', 'updated_at' => now()]);

        self::audit('advertising.campaign_waived', (int) $c->provider_profile_id,
            ['campaign_id' => $campaignId, 'admin_id' => $adminId, 'reason' => $reason]);
    }

    /* --------------------------------------------------------------- money */

    /**
     * Write down a payment somebody else took. Spec 20.21.
     *
     * ⛔ IDEMPOTENT BY UNIQUE INDEX, NOT BY A CHECK-THEN-INSERT. Webhooks are
     * retried - that is their design, not a fault - and two retries arriving
     * together would both pass an `if (!exists)` and both insert. The unique
     * index on (billing_provider, external_payment_reference) makes the second
     * one fail at the database, so the race has nowhere to happen. Catching
     * that failure and returning the existing row is the whole handler.
     *
     * @param  array{provider_profile_id: int, subject_type: string, subject_id: int,
     *               billing_provider: string, external_payment_reference: string,
     *               amount: float, currency: string, status?: string,
     *               receipt_reference?: ?string}  $p
     *
     * @throws AdvertisingRefused
     */
    public static function recordPayment(array $p): int
    {
        foreach (['provider_profile_id', 'subject_type', 'subject_id',
                  'billing_provider', 'external_payment_reference', 'amount', 'currency'] as $need) {
            if (!isset($p[$need]) || $p[$need] === '') {
                throw new AdvertisingRefused('A payment record needs ' . $need . '.');
            }
        }

        if (!in_array($p['subject_type'], ['plan', 'campaign'], true)) {
            throw new AdvertisingRefused('A payment is for a plan or a campaign.');
        }

        $status = (string) ($p['status'] ?? 'paid');

        $existing = DB::table('advertising_payments')
            ->where('billing_provider', $p['billing_provider'])
            ->where('external_payment_reference', $p['external_payment_reference'])
            ->value('id');

        if ($existing !== null) {
            // The retry. Already recorded, already applied, nothing to do -
            // and importantly, the campaign is not credited a second time.
            return (int) $existing;
        }

        return DB::transaction(function () use ($p, $status) {
            $id = (int) DB::table('advertising_payments')->insertGetId([
                'provider_profile_id'        => (int) $p['provider_profile_id'],
                'subject_type'               => $p['subject_type'],
                'subject_id'                 => (int) $p['subject_id'],
                'billing_provider'           => $p['billing_provider'],
                'external_payment_reference' => $p['external_payment_reference'],
                'amount'                     => (float) $p['amount'],
                'currency'                   => strtoupper((string) $p['currency']),
                'status'                     => $status,
                'paid_at'                    => $status === 'paid' ? now() : null,
                'receipt_reference'          => $p['receipt_reference'] ?? null,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);

            if ($status === 'paid' && $p['subject_type'] === 'campaign') {
                DB::table('sponsored_campaigns')->where('id', (int) $p['subject_id'])
                    ->update(['payment_status' => 'paid', 'updated_at' => now()]);
            }

            self::audit('advertising.payment_recorded', (int) $p['provider_profile_id'],
                ['payment_id' => $id, 'status' => $status]);

            return $id;
        });
    }

    /* ------------------------------------------------------------- reading */

    /**
     * Campaigns eligible to be shown right now, in this country.
     *
     * @return list<object>
     */
    public static function live(string $country, ?int $categoryId = null): array
    {
        $country = strtoupper($country);

        if (FeatureFlags::off('sponsored_listings_enabled', $country)) {
            return [];
        }

        $q = DB::table('sponsored_campaigns')
            ->where('country_code', $country)
            ->where('status', 'active')
            ->whereIn('payment_status', ['paid', 'waived'])
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereNull('deleted_at');

        if ($categoryId !== null) {
            // A campaign with no category is a general one and matches anything.
            $q->where(fn ($w) => $w->whereNull('category_id')->orWhere('category_id', $categoryId));
        }

        return $q->get()->all();
    }

    private static function audit(string $event, int $providerId, ?array $after, ?string $reason = null): void
    {
        DB::table('audit_events')->insert([
            'event'        => $event,
            'subject_type' => 'advertising',
            'subject_id'   => $providerId,
            'after'        => $after === null ? null : json_encode($after),
            'reason'       => $reason,
            'created_at'   => now(),
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advertising: money paid by a provider to NearbyPost for visibility. Spec 20.21.
 *
 * Spec 20.21 is explicit that this is NOT buyer-to-merchant money: nobody buys
 * a nasi lemak through here. It is a provider paying us to be seen, which is a
 * much narrower thing and needs a much smaller schema.
 *
 * ⛔⛔ NO CARD DETAILS ANYWHERE, AND NO COLUMN THAT COULD HOLD ONE. Spec 20.21:
 * "Do not store raw card details. Use the existing approved payment provider."
 * There is no pan, no cvv, no expiry, no cardholder name and no free-text
 * column shaped like a place to put them. What is stored is somebody else's
 * reference to a payment they hold. marketplace:check asserts these absences,
 * because "we decided not to" is not a control.
 *
 * ⛔ THE DISCLOSURE LABEL IS A NOT-NULL COLUMN WITH A CHECK. Spec 15.4 requires
 * a sponsored placement to be labelled and never disguised as an editorial
 * recommendation. If the label were nullable, an unlabelled campaign would be
 * one forgotten default away, and it would look exactly like an ordinary
 * result. A campaign that cannot be labelled cannot exist.
 *
 * ⛔ AND NOTHING ADVERTISES BY ACCIDENT. A campaign may only be `active` once a
 * payment is recorded or an admin has deliberately waived it. There is no
 * payment provider wired up yet, so today every campaign a person could create
 * stops at `pending_payment` - which is the honest state, not a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * What may be bought, per country. Spec 20.21.
         *
         * Country-scoped because advertising rules are national: what a
         * Malaysian provider may be sold is not what a Singaporean one may be,
         * and professional-services advertising is restricted in both.
         */
        Schema::create('advertising_plans', function (Blueprint $t) {
            $t->id();
            $t->char('country_code', 2);
            $t->string('code', 40);
            $t->string('name', 120);

            // subscription | campaign | one_off
            $t->string('billing_type', 20);

            $t->decimal('price', 12, 2);
            $t->char('currency', 3);
            $t->unsignedSmallInteger('duration_days')->nullable();

            // What the money buys, as data rather than an if/else ladder in a
            // controller (spec 4.3): slots, categories, whether the plan may
            // sponsor at all.
            $t->jsonb('entitlements')->nullable();

            $t->boolean('active')->default(false);
            $t->timestamps();

            $t->unique(['country_code', 'code'], 'ad_plan_code_per_country');
            $t->index(['country_code', 'active'], 'ad_plan_country_active');
        });

        DB::statement("ALTER TABLE advertising_plans
                       ADD CONSTRAINT ad_plan_billing_type
                       CHECK (billing_type IN ('subscription','campaign','one_off'))");

        DB::statement('ALTER TABLE advertising_plans
                       ADD CONSTRAINT ad_plan_price_not_negative CHECK (price >= 0)');

        /**
         * A provider's standing arrangement. Spec 20.21.
         *
         * ⛔ `external_subscription_reference` is the provider's id at the
         * billing company, and it is the ONLY thing we keep about the payment
         * instrument. We hold a pointer; they hold the money and the card.
         */
        Schema::create('provider_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('provider_profile_id');
            $t->unsignedBigInteger('advertising_plan_id');

            $t->string('billing_provider', 40)->nullable();
            $t->string('external_subscription_reference', 120)->nullable();

            // pending_payment | active | past_due | cancelled | expired
            $t->string('status', 20)->default('pending_payment');

            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();

            $t->index(['provider_profile_id', 'status'], 'ad_sub_provider_status');
        });

        // ⛔ One live subscription per provider, enforced by a PARTIAL unique
        // index rather than by whoever writes the next controller. Two active
        // subscriptions means two sets of entitlements and an unanswerable
        // question about which one was paid for.
        DB::statement("CREATE UNIQUE INDEX ad_sub_one_active_per_provider
                       ON provider_subscriptions (provider_profile_id)
                       WHERE status = 'active'");

        /**
         * One paid placement. Spec 20.21, and the rules in 15.4.
         *
         * Geography is a centre and a radius, in the same shape the search
         * already speaks, so a campaign cannot claim a reach the search cannot
         * express.
         */
        Schema::create('sponsored_campaigns', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->unsignedBigInteger('provider_profile_id');

            // Either a specific listing, or the provider generally.
            $t->unsignedBigInteger('listing_id')->nullable();
            $t->unsignedBigInteger('category_id')->nullable();

            $t->char('country_code', 2);
            $t->decimal('centre_lat', 10, 7)->nullable();
            $t->decimal('centre_lng', 10, 7)->nullable();
            $t->unsignedInteger('radius_metres')->nullable();

            $t->timestamp('starts_at');
            $t->timestamp('ends_at');

            $t->decimal('budget', 12, 2)->default(0);
            $t->char('currency', 3);

            // draft | pending_payment | active | paused | ended | refused
            $t->string('status', 20)->default('draft');

            // unpaid | paid | waived
            $t->string('payment_status', 20)->default('unpaid');

            // ⛔ NOT NULL. See the class note: an unlabelled sponsored result is
            // the exact thing spec 15.4 forbids, so it must be unrepresentable.
            $t->string('disclosure_label', 40)->default('Sponsored');

            $t->timestamps();
            $t->softDeletes();

            $t->index(['country_code', 'status', 'starts_at', 'ends_at'], 'ad_campaign_live');
            $t->index(['provider_profile_id', 'status'], 'ad_campaign_provider');
            $t->index(['category_id'], 'ad_campaign_category');
        });

        DB::statement("ALTER TABLE sponsored_campaigns
                       ADD CONSTRAINT ad_campaign_label_present
                       CHECK (btrim(disclosure_label) <> '')");

        DB::statement('ALTER TABLE sponsored_campaigns
                       ADD CONSTRAINT ad_campaign_ends_after_start
                       CHECK (ends_at > starts_at)');

        DB::statement("ALTER TABLE sponsored_campaigns
                       ADD CONSTRAINT ad_campaign_payment_status
                       CHECK (payment_status IN ('unpaid','paid','waived'))");

        // ⛔ NOTHING ADVERTISES FOR FREE BY ACCIDENT. Running while unpaid is
        // not a state anybody chooses deliberately - it is what a half-finished
        // checkout leaves behind, and it would put a paid slot in front of
        // readers with nobody having agreed to anything.
        DB::statement("ALTER TABLE sponsored_campaigns
                       ADD CONSTRAINT ad_campaign_active_needs_settlement
                       CHECK (status <> 'active' OR payment_status IN ('paid','waived'))");

        DB::statement('ALTER TABLE sponsored_campaigns
                       ADD CONSTRAINT ad_campaign_budget_not_negative CHECK (budget >= 0)');

        // A campaign that names a radius must name a centre, or it reaches
        // everywhere and the geography restriction in 15.4 is decoration.
        DB::statement('ALTER TABLE sponsored_campaigns
                       ADD CONSTRAINT ad_campaign_radius_needs_centre
                       CHECK (radius_metres IS NULL
                              OR (centre_lat IS NOT NULL AND centre_lng IS NOT NULL))');

        /**
         * Money that changed hands somewhere else. Spec 20.21.
         *
         * ⛔ THE UNIQUE INDEX IS THE IDEMPOTENCY. Spec 20.21: "Webhooks must be
         * signature-verified and idempotent." A retried webhook - and they are
         * always retried - must not credit a campaign twice. Idempotency
         * written as an `if (already exists)` in a handler is a race; written
         * as a unique index it is a guarantee, and the second insert simply
         * fails.
         */
        Schema::create('advertising_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('provider_profile_id');

            // plan | campaign - what was bought
            $t->string('subject_type', 20);
            $t->unsignedBigInteger('subject_id');

            $t->string('billing_provider', 40);
            $t->string('external_payment_reference', 120);

            $t->decimal('amount', 12, 2);
            $t->char('currency', 3);

            // pending | paid | failed | refunded
            $t->string('status', 20)->default('pending');

            $t->timestamp('paid_at')->nullable();
            $t->string('receipt_reference', 120)->nullable();
            $t->timestamps();

            $t->unique(['billing_provider', 'external_payment_reference'], 'ad_payment_idempotent');
            $t->index(['provider_profile_id', 'status'], 'ad_payment_provider');
            $t->index(['subject_type', 'subject_id'], 'ad_payment_subject');
        });

        DB::statement("ALTER TABLE advertising_payments
                       ADD CONSTRAINT ad_payment_subject_type
                       CHECK (subject_type IN ('plan','campaign'))");

        DB::statement("ALTER TABLE advertising_payments
                       ADD CONSTRAINT ad_payment_paid_has_a_date
                       CHECK (status <> 'paid' OR paid_at IS NOT NULL)");

        /**
         * What a campaign actually got. Spec 15.4: "Record campaign and
         * impression/click/contact events."
         *
         * Kept deliberately thin. This is a billing and reporting record, not
         * an audience profile: there is no user id, no IP, no user agent and no
         * referrer. A daily-rotated hash exists only so one reader refreshing a
         * page twenty times is not sold as twenty impressions, and it cannot be
         * joined back to a person.
         */
        Schema::create('sponsored_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('listing_id')->nullable();

            // impression | click | contact
            $t->string('event_type', 12);

            $t->char('viewer_bucket', 64)->nullable();
            $t->timestamp('occurred_at');

            $t->index(['campaign_id', 'event_type', 'occurred_at'], 'ad_event_campaign');
        });

        DB::statement("ALTER TABLE sponsored_events
                       ADD CONSTRAINT ad_event_type
                       CHECK (event_type IN ('impression','click','contact'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsored_events');
        Schema::dropIfExists('advertising_payments');
        Schema::dropIfExists('sponsored_campaigns');
        Schema::dropIfExists('provider_subscriptions');
        Schema::dropIfExists('advertising_plans');
    }
};

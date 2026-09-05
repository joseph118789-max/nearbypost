<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Property, mirrored from ListingMine. Spec 27.
 *
 * ⛔⛔ LISTINGMINE IS THE SOURCE OF TRUTH, AND THAT IS ENFORCED BY A TRIGGER
 * RATHER THAN BY EVERYONE REMEMBERING.
 *
 * Spec 27: "Do not allow NearbyPost to overwrite ListingMine property data."
 * The phase is done when "a change in ListingMine flows through and no
 * NearbyPost action can overwrite it" - and "no action" cannot be a habit. A
 * moderation screen, an admin tool, a migration written in a year's time or a
 * well-meaning bulk UPDATE would each be one line away from silently editing
 * another system's records, and the damage would be invisible: NearbyPost would
 * simply start showing a price that ListingMine never set.
 *
 * So the mirrored columns are guarded in the database. Any UPDATE that changes
 * one is refused unless the session has declared itself a sync, which only
 * PropertyMirror::sync() does. Everything else - including a direct query
 * builder call from anywhere in this codebase - gets an exception.
 *
 * THE COLUMNS SPLIT IN TWO, and the split is the whole design:
 *
 *   - MIRRORED: what ListingMine owns. Read here, never written here.
 *   - LOCAL: what NearbyPost legitimately owns - whether we choose to show it,
 *     why we hid it, when we last synced. Freely writable, because hiding a
 *     listing on our own site is our decision and does not touch theirs.
 *
 * ⛔ There is deliberately NO editing workflow and no local title, price or
 * description override (spec 27: "Do not duplicate property editing workflows
 * in Marketplace"). The only lever NearbyPost has is `local_visibility`.
 */
return new class extends Migration
{
    /** The columns ListingMine owns. The trigger below is built from this list. */
    private const MIRRORED = [
        'listingmine_property_id', 'canonical_url', 'agent_reference', 'agent_name',
        'title', 'property_type', 'deal_type', 'price', 'currency', 'price_period',
        'country_code', 'region_code', 'city_name', 'public_lat', 'public_lng',
        'main_image_url', 'bedrooms', 'bathrooms', 'built_up_sqft',
        'source_status', 'source_updated_at',
    ];

    public function up(): void
    {
        Schema::create('property_listings', function (Blueprint $t) {
            $t->id();

            // Ours, so nothing public ever exposes a sequential id (spec 29).
            $t->uuid('public_uuid')->unique();

            /* ---- mirrored from ListingMine: read here, never written here ---- */

            $t->string('listingmine_property_id', 64)->unique();
            $t->string('canonical_url', 1000);
            $t->string('agent_reference', 64)->nullable();
            $t->string('agent_name', 160)->nullable();

            $t->string('title', 300);
            $t->string('property_type', 60)->nullable();

            // sale | rent
            $t->string('deal_type', 10);

            $t->decimal('price', 14, 2)->nullable();
            $t->char('currency', 3)->nullable();

            // for rent: month | week - null for a sale
            $t->string('price_period', 10)->nullable();

            $t->char('country_code', 2);
            $t->string('region_code', 40)->nullable();
            $t->string('city_name', 120)->nullable();

            // A property for sale advertises its own address; there is no
            // private point here to protect, and none is ever received.
            $t->decimal('public_lat', 10, 7)->nullable();
            $t->decimal('public_lng', 10, 7)->nullable();

            // Only what ListingMine says may be displayed.
            $t->string('main_image_url', 1000)->nullable();

            $t->unsignedSmallInteger('bedrooms')->nullable();
            $t->unsignedSmallInteger('bathrooms')->nullable();
            $t->unsignedInteger('built_up_sqft')->nullable();

            // Their word for it: active | sold | rented | withdrawn | expired ...
            $t->string('source_status', 30);

            // ⛔ Their clock, not ours. This is what makes a late or replayed
            // payload safe to ignore.
            $t->timestamp('source_updated_at');

            /* ---------------- local: ours to write, theirs to ignore --------- */

            // visible | hidden_locally - the ONLY lever NearbyPost has.
            $t->string('local_visibility', 20)->default('visible');
            $t->string('hidden_reason', 200)->nullable();
            $t->timestamp('last_synced_at')->nullable();

            $t->timestamps();

            $t->index(['country_code', 'source_status', 'local_visibility'], 'property_live');
            $t->index(['public_lat', 'public_lng'], 'property_geo');
            $t->index(['deal_type', 'country_code'], 'property_deal');
        });

        DB::statement("ALTER TABLE property_listings
                       ADD CONSTRAINT property_deal_type CHECK (deal_type IN ('sale','rent'))");

        DB::statement("ALTER TABLE property_listings
                       ADD CONSTRAINT property_local_visibility
                       CHECK (local_visibility IN ('visible','hidden_locally'))");

        // ⛔ A hidden listing must say why. "Hidden by somebody, at some point,
        // for some reason" is the state nobody can ever safely reverse.
        DB::statement("ALTER TABLE property_listings
                       ADD CONSTRAINT property_hidden_has_a_reason
                       CHECK (local_visibility <> 'hidden_locally' OR btrim(coalesce(hidden_reason,'')) <> '')");

        // ⛔ Spec 29 and 27: the canonical URL is rendered as an outbound link,
        // so a non-https or non-ListingMine URL arriving in a payload must not
        // even land. The service checks the host too; this stops the shapes
        // that are never right under any host.
        DB::statement("ALTER TABLE property_listings
                       ADD CONSTRAINT property_canonical_url_is_https
                       CHECK (canonical_url LIKE 'https://%')");

        /**
         * The guard. See the class note.
         *
         * `current_setting(..., true)` returns NULL rather than raising when the
         * setting has never been set, so an ordinary session - which is every
         * session except a sync - trips the exception.
         */
        $checks = implode("\n            OR ", array_map(
            fn ($c) => "NEW.{$c} IS DISTINCT FROM OLD.{$c}",
            self::MIRRORED
        ));

        DB::unprepared("
            CREATE OR REPLACE FUNCTION property_listings_source_of_truth()
            RETURNS trigger AS \$\$
            BEGIN
                IF current_setting('app.listingmine_sync', true) IS DISTINCT FROM 'on' THEN
                    IF {$checks}
                    THEN
                        RAISE EXCEPTION
                            'ListingMine owns this property record. NearbyPost may set local_visibility and hidden_reason only.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::unprepared('
            CREATE TRIGGER property_listings_guard
            BEFORE UPDATE ON property_listings
            FOR EACH ROW EXECUTE FUNCTION property_listings_source_of_truth();
        ');

        /**
         * Outbound traffic to ListingMine. Spec 27: "Track outbound traffic."
         *
         * Thin on purpose, exactly like sponsored_events: no user id, no IP, no
         * user agent, no referrer. NearbyPost's claim to ListingMine is "we
         * sent you this many readers", and that needs a count, not a profile.
         */
        Schema::create('property_click_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_listing_id');
            $t->char('viewer_bucket', 64)->nullable();
            $t->timestamp('occurred_at');

            $t->index(['property_listing_id', 'occurred_at'], 'property_click_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_click_events');
        DB::unprepared('DROP TRIGGER IF EXISTS property_listings_guard ON property_listings');
        DB::unprepared('DROP FUNCTION IF EXISTS property_listings_source_of_truth()');
        Schema::dropIfExists('property_listings');
    }
};

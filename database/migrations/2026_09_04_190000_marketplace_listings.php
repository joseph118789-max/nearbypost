<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A directory advertisement or offer. Spec §20.8, §20.9.
 *
 * ⛔ PUBLICATION AND MODERATION ARE TWO STATE MACHINES, NOT ONE COLUMN.
 *
 * Spec §13.4 says so explicitly - "Do not encode all of these in a single
 * ambiguous status column" - and the reason is visible elsewhere in this
 * codebase already: news_items carries `status`, `review_status`, `discarded`
 * and `error_code`, and working out whether a story is live means reading all
 * four. A listing paused by its owner while it happens to be under manual
 * review has to come back with both facts intact when the review finishes.
 *
 *   publication_status  what the OWNER and the calendar decided
 *   moderation_status   what the MODERATOR and the AI decided
 *
 * A listing is visible only when both agree, which is a single predicate in
 * one scope rather than a condition each caller re-invents. That mistake has
 * its own memory in this project: the live screen's buckets and its drill-downs
 * used different predicates and disagreed with each other for weeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_listings', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->string('slug', 200)->nullable()->unique();

            // The human who is answerable for it, always. A provider profile is
            // optional because an occasional personal sale has no provider
            // (§8.4) - the account itself is the seller.
            $t->unsignedBigInteger('owner_user_id');
            $t->unsignedBigInteger('provider_profile_id')->nullable();

            $t->unsignedBigInteger('category_id');

            // provider_service | food_offer | promotion | personal_item
            $t->string('listing_kind', 24);

            $t->string('title', 200);
            $t->text('description')->nullable();

            // Category-specific fields, shaped by category_country_rules.field_schema.
            // ⛔ Validated against that schema before write - never trusted raw.
            $t->jsonb('structured_attributes')->nullable();

            $t->string('country_code', 2);
            $t->string('region_code', 12)->nullable();
            $t->string('city_name', 120)->nullable();

            // exact_premises | approximate_area | service_area | online_only
            $t->string('public_location_mode', 24)->default('approximate_area');
            $t->decimal('public_lat', 10, 7)->nullable();
            $t->decimal('public_lng', 10, 7)->nullable();

            // ⛔ Never selected into a public query. See provider_profiles.
            $t->decimal('private_lat', 10, 7)->nullable();
            $t->decimal('private_lng', 10, 7)->nullable();

            $t->unsignedInteger('service_radius_metres')->nullable();

            // fixed | from | range | quotation | free
            $t->string('price_mode', 12)->default('quotation');
            $t->decimal('price_min', 12, 2)->nullable();
            $t->decimal('price_max', 12, 2)->nullable();
            $t->string('currency', 3)->nullable();

            $t->unsignedInteger('quantity_available')->nullable();
            $t->timestamp('availability_starts_at')->nullable();
            $t->timestamp('availability_ends_at')->nullable();
            $t->timestamp('expires_at');

            // draft | submitted | published | paused | expired | sold | suspended | removed | rejected
            $t->string('publication_status', 16)->default('draft');

            // not_checked | ai_checking | ai_accepted | ai_rejected
            //            | manual_review | manual_accepted | manual_rejected | recheck_required
            $t->string('moderation_status', 20)->default('not_checked');

            // none | sponsored
            $t->string('sponsorship_status', 12)->default('none');

            // What was true about the provider when this was published, so a
            // later change to their verification does not silently rewrite
            // history on a listing already seen by readers.
            $t->jsonb('verification_snapshot')->nullable();

            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('provider_profile_id')->references('id')->on('provider_profiles')->nullOnDelete();
        });

        // The listing feed's own predicate: country, then category, then the
        // two states, then expiry. Spec §20.22 asks for exactly this composite.
        DB::statement("CREATE INDEX listings_live_lookup_idx
                       ON marketplace_listings (country_code, category_id, expires_at)
                       WHERE publication_status = 'published'
                         AND moderation_status IN ('ai_accepted', 'manual_accepted')
                         AND deleted_at IS NULL");

        // The radius search. Partial for the same reason: a draft or expired
        // listing has no business being in the geographic index at all, and
        // leaving it out keeps the index small enough to stay cached.
        DB::statement("CREATE INDEX listings_live_geo_idx
                       ON marketplace_listings (public_lat, public_lng)
                       WHERE publication_status = 'published'
                         AND moderation_status IN ('ai_accepted', 'manual_accepted')
                         AND public_lat IS NOT NULL
                         AND deleted_at IS NULL");

        // A provider's own dashboard: their listings by state, newest first.
        DB::statement('CREATE INDEX listings_provider_state_idx
                       ON marketplace_listings (provider_profile_id, publication_status, created_at DESC)');

        // The expiry job, which must not scan the table to find its work.
        DB::statement("CREATE INDEX listings_expiry_sweep_idx
                       ON marketplace_listings (expires_at)
                       WHERE publication_status = 'published'");

        // ⛔ A RANGE PRICE MUST BE A RANGE. price_max below price_min is not a
        // state any screen can render honestly, and it is cheaper to refuse it
        // here than to defend against it in every template.
        DB::statement('ALTER TABLE marketplace_listings
                       ADD CONSTRAINT listings_price_range_sane
                       CHECK (price_min IS NULL OR price_max IS NULL OR price_max >= price_min)');

        // ⛔ A LISTING WITH A LOCATION MODE THAT NEEDS A POINT MUST HAVE ONE.
        // "exact_premises" with no coordinates renders as a pin at null island.
        DB::statement("ALTER TABLE marketplace_listings
                       ADD CONSTRAINT listings_located_when_claimed
                       CHECK (public_location_mode NOT IN ('exact_premises', 'approximate_area')
                              OR publication_status <> 'published'
                              OR (public_lat IS NOT NULL AND public_lng IS NOT NULL))");

        Schema::create('listing_media', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_id');
            $t->unsignedBigInteger('media_id');
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('caption', 300)->nullable();
            $t->timestamps();

            $t->unique(['listing_id', 'media_id'], 'listing_media_unique');
            $t->index(['listing_id', 'sort_order'], 'listing_media_order_idx');

            $t->foreign('listing_id')->references('id')->on('marketplace_listings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_media');
        Schema::dropIfExists('marketplace_listings');
    }
};

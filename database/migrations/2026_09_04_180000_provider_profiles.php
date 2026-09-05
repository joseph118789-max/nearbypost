<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A public identity that offers goods or services. Spec §20.2, §20.3, §20.7.
 *
 * One person, one login, many of these. The personal account stays in `users`
 * and keeps the community avatar; each provider profile carries its own name,
 * image, rating and listing history, and nothing merges (§3.2, §3.3).
 *
 * ⛔ PUBLIC AND PRIVATE LOCATION ARE DIFFERENT COLUMNS, AND ONLY ONE IS SHOWN.
 * A home-based provider gives an address so the business registration can be
 * checked; that address must never become the public map pin (§13.2). The
 * public geometry is derived by the server from public_location_mode, and the
 * private one is never selected into a public query. Storing them in one
 * column and "being careful" is how a home address ends up on a map.
 *
 * ⛔ COORDINATES ARE numeric FOR NOW. PostGIS is not installed on this server -
 * measured 4 Sep 2026, the extensions are pg_trgm, unaccent and plpgsql - so
 * radius search is a bounding box plus haversine, which saturates this 4-core
 * box at roughly 100 queries a second. If PostGIS is added later, a geography
 * column and a GiST index go beside these, the search service switches over in
 * one place, and these columns stay as the human-readable copy. Nothing else
 * in the schema needs to change, which is the point of keeping the search in
 * one class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $t) {
            $t->id();

            // ⛔ Public URLs use this, never the id. Spec §29: "Do not expose
            // sequential internal IDs when public UUIDs/slugs are available."
            $t->uuid('public_uuid')->unique();
            $t->string('slug', 160)->unique();

            // neighbour_provider | registered_business | organisation | professional_firm
            $t->string('provider_kind', 32);

            // home_based | premises | mobile_service_area | online_only
            $t->string('operating_mode', 32);

            $t->string('public_name', 160);

            // ⛔ Protected. A registered business's legal entity name may differ
            // from its trading name and is shown only where policy requires.
            $t->string('legal_name', 200)->nullable();

            $t->unsignedBigInteger('primary_media_id')->nullable();
            $t->unsignedBigInteger('cover_media_id')->nullable();
            $t->text('description')->nullable();

            $t->string('country_code', 2);
            $t->string('region_code', 12)->nullable();     // MY-14 etc, matching boundaries.code
            $t->string('city_name', 120)->nullable();

            // exact_premises | approximate_area | service_area | online_only
            $t->string('public_location_mode', 24)->default('approximate_area');

            // What the public sees. Derived from the private point according to
            // public_location_mode - never copied from it verbatim.
            $t->decimal('public_lat', 10, 7)->nullable();
            $t->decimal('public_lng', 10, 7)->nullable();

            // ⛔ NEVER SELECTED INTO A PUBLIC QUERY. For verification only.
            $t->decimal('private_lat', 10, 7)->nullable();
            $t->decimal('private_lng', 10, 7)->nullable();
            $t->text('private_address')->nullable();

            $t->unsignedInteger('service_radius_metres')->nullable();

            $t->string('whatsapp_country_code', 6)->nullable();
            $t->string('whatsapp_number', 24)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('website_url', 300)->nullable();

            // not_provided | submitted | confirmed | rejected | not_applicable
            $t->string('registration_status', 24)->default('not_provided');
            $t->string('registration_authority', 160)->nullable();
            $t->string('registration_number', 80)->nullable();   // protected in display

            // ⛔ A SUMMARY, NEVER A BADGE. The words a reader sees come from the
            // verification_checks rows, which each name one checked fact
            // (§3.4). Nothing here may ever render as "Trusted Merchant".
            $t->string('verification_summary_status', 32)->default('none');

            // draft | published | paused | suspended | removed
            $t->string('publication_status', 16)->default('draft');

            $t->unsignedBigInteger('created_by_user_id');
            $t->timestamps();
            $t->softDeletes();

            $t->index(['country_code', 'publication_status'], 'provider_country_status_idx');
            $t->index(['publication_status', 'public_lat', 'public_lng'], 'provider_status_geo_idx');
            $t->index('created_by_user_id', 'provider_creator_idx');
        });

        // Who may act for a provider, and how much. Spec §8.6, §21.2.
        Schema::create('provider_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('provider_profile_id');
            $t->unsignedBigInteger('user_id');

            // owner | manager | content_editor | support | viewer
            $t->string('role', 20);

            // invited | active | suspended | left
            $t->string('status', 12)->default('invited');

            $t->unsignedBigInteger('invited_by_user_id')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();

            $t->unique(['provider_profile_id', 'user_id'], 'provider_member_unique');
            $t->index(['user_id', 'status'], 'provider_member_user_idx');

            $t->foreign('provider_profile_id')->references('id')->on('provider_profiles')->cascadeOnDelete();
        });

        // Which categories a provider is approved to advertise in. Spec §20.7.
        Schema::create('provider_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('provider_profile_id');
            $t->unsignedBigInteger('category_id');

            // requested | approved | rejected
            $t->string('status', 12)->default('requested');
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by_user_id')->nullable();
            $t->timestamps();

            $t->unique(['provider_profile_id', 'category_id'], 'provider_category_unique');

            $t->foreign('provider_profile_id')->references('id')->on('provider_profiles')->cascadeOnDelete();
        });

        // ⛔ EXACTLY ONE OWNER PER PROVIDER, ENFORCED BY THE DATABASE.
        //
        // Spec §8.6: only an owner may delete a profile or transfer ownership.
        // Left to application code, a half-finished transfer leaves two owners
        // or none, and both are worse than a failed transfer. A partial unique
        // index says it once, for every code path that will ever exist.
        DB::statement("CREATE UNIQUE INDEX provider_single_owner_idx
                       ON provider_members (provider_profile_id)
                       WHERE role = 'owner' AND status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_categories');
        Schema::dropIfExists('provider_members');
        Schema::dropIfExists('provider_profiles');
    }
};

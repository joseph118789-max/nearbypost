<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Car Pool: a journey noticeboard. Spec §12, §20.13, §20.14.
 *
 * ⛔⛔ THIS IS NOT TRANSPORT SOFTWARE, AND THE SCHEMA HAS TO MAKE THAT TRUE
 * RATHER THAN MERELY INTENDED.
 *
 * Spec §12.1: "NearbyPost does not operate the vehicle, set a fare, collect
 * payment, guarantee a seat or confirm a booking." So there is no fare column,
 * no booking table, no seat reservation and no payment reference anywhere here.
 * A column called `fare` would be filled in within a month, and the day it is,
 * the site is a transport operator in the eyes of a regulator - which is the
 * distinction §12.5 exists to defend.
 *
 * What exists instead: `cost_share_mode`, three values, none of them a number.
 *
 * ⛔ AND THE FLAG STAYS OFF. Spec §12.1: "Enable per country only after local
 * transport-law review." carpool_enabled is in the list of flags a country must
 * opt into by name, so running this migration opens nothing anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * The one-time activation. Spec §12.2.
         *
         * ⛔ THE VEHICLE REGISTRATION IS ENCRYPTED AND NEVER PUBLIC. Spec
         * §19.4: "Do not expose identity documents, licence documents or full
         * vehicle registration publicly." It exists so a passenger who reports
         * a driver can be matched to a real vehicle, not so the plate can be
         * shown on a listing.
         */
        Schema::create('carpool_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');

            // none | active | suspended - kept apart, because somebody may
            // drive and never ride, or ride and never drive.
            $t->string('driver_status', 12)->default('none');
            $t->string('passenger_status', 12)->default('none');

            // Declarations, not documents. Spec §12.2 asks the driver to state
            // these; the site records WHEN they said it, so the statement has a
            // date attached to it.
            $t->timestamp('driving_declaration_at')->nullable();
            $t->timestamp('road_registration_declaration_at')->nullable();
            $t->timestamp('insurance_declaration_at')->nullable();
            $t->timestamp('non_commercial_declaration_at')->nullable();
            $t->timestamp('adult_declaration_at')->nullable();

            $t->string('vehicle_make', 60)->nullable();
            $t->string('vehicle_model', 60)->nullable();
            $t->string('vehicle_colour', 30)->nullable();

            // ⛔ Encrypted at rest by the model cast. Never selected into a
            // public query, never rendered, never in a search index.
            $t->text('vehicle_registration_encrypted')->nullable();

            $t->unsignedTinyInteger('vehicle_seat_capacity')->nullable();

            $t->string('safety_terms_version', 20)->nullable();
            $t->timestamp('safety_terms_accepted_at')->nullable();

            $t->timestamps();
            $t->unique('user_id', 'carpool_profile_one_per_user');
        });

        // ⛔ A DRIVER IS NOT ACTIVE WITHOUT EVERY DECLARATION. Spec §12.2 lists
        // them, and a partial activation is the state where somebody is
        // carrying passengers having told us nothing about insurance.
        DB::statement("ALTER TABLE carpool_profiles
                       ADD CONSTRAINT carpool_driver_declared_everything
                       CHECK (driver_status <> 'active' OR (
                           driving_declaration_at IS NOT NULL
                           AND road_registration_declaration_at IS NOT NULL
                           AND insurance_declaration_at IS NOT NULL
                           AND non_commercial_declaration_at IS NOT NULL
                           AND adult_declaration_at IS NOT NULL
                           AND vehicle_seat_capacity IS NOT NULL
                           AND safety_terms_accepted_at IS NOT NULL))");

        DB::statement("ALTER TABLE carpool_profiles
                       ADD CONSTRAINT carpool_passenger_declared_everything
                       CHECK (passenger_status <> 'active' OR (
                           adult_declaration_at IS NOT NULL
                           AND safety_terms_accepted_at IS NOT NULL))");

        /**
         * One journey notice. Spec §12.3, §12.4, §20.14.
         *
         * ⛔ THE PUBLIC POINTS ARE COARSE AND THE PRIVATE ONES ARE NOT SHOWN.
         * Spec §34.5: "Exact home points are not public by default." A journey
         * that starts at somebody's front door would otherwise publish their
         * address on a schedule, which is worse than publishing it once.
         */
        Schema::create('carpool_notices', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->unsignedBigInteger('user_id');

            // seats_available | looking_for_ride
            $t->string('notice_type', 20);

            $t->decimal('origin_private_lat', 10, 7)->nullable();
            $t->decimal('origin_private_lng', 10, 7)->nullable();
            $t->decimal('origin_public_lat', 10, 7);
            $t->decimal('origin_public_lng', 10, 7);
            $t->string('origin_area', 120)->nullable();

            $t->decimal('destination_private_lat', 10, 7)->nullable();
            $t->decimal('destination_private_lng', 10, 7)->nullable();
            $t->decimal('destination_public_lat', 10, 7);
            $t->decimal('destination_public_lng', 10, 7);
            $t->string('destination_area', 120)->nullable();

            $t->jsonb('stops')->nullable();

            $t->timestamp('departure_at');
            $t->unsignedSmallInteger('flexibility_minutes')->default(0);
            $t->string('recurrence_rule', 120)->nullable();

            $t->unsignedTinyInteger('seat_count')->nullable();
            $t->unsignedTinyInteger('passenger_count')->nullable();

            // ⛔ free | shared_expenses | discuss — and NOTHING ELSE. There is
            // no amount, because an amount is a fare and a fare is a service
            // (§12.1, §12.5).
            $t->string('cost_share_mode', 20)->default('discuss');

            $t->string('notes', 1000)->nullable();

            // draft | published | cancelled | expired | suspended | removed
            $t->string('publication_status', 16)->default('draft');
            $t->string('moderation_status', 20)->default('not_checked');

            $t->timestamp('expires_at');
            $t->timestamps();
            $t->softDeletes();

            $t->index(['publication_status', 'departure_at'], 'carpool_departure_idx');
            $t->index(['notice_type', 'origin_public_lat', 'origin_public_lng'], 'carpool_origin_idx');
            $t->index(['expires_at'], 'carpool_expiry_idx');
        });

        // ⛔ SEATS BELONG TO A DRIVER, PASSENGERS TO A PASSENGER. A "seats
        // available" notice with a passenger count, or the reverse, is a form
        // filled in wrongly and would match against the wrong side.
        DB::statement("ALTER TABLE carpool_notices
                       ADD CONSTRAINT carpool_counts_match_type
                       CHECK ((notice_type = 'seats_available' AND seat_count IS NOT NULL AND passenger_count IS NULL)
                           OR (notice_type = 'looking_for_ride' AND passenger_count IS NOT NULL AND seat_count IS NULL))");

        // ⛔ A NOTICE CANNOT OUTLIVE ITS JOURNEY. Spec §28: "Car-pool notices
        // should normally be removed/noindexed after departure because they are
        // temporary and privacy-sensitive." Expiry is derived from departure,
        // never chosen.
        DB::statement('ALTER TABLE carpool_notices
                       ADD CONSTRAINT carpool_expires_after_departure
                       CHECK (expires_at >= departure_at)');

        DB::statement("ALTER TABLE carpool_notices
                       ADD CONSTRAINT carpool_cost_share_is_not_a_fare
                       CHECK (cost_share_mode IN ('free','shared_expenses','discuss'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('carpool_notices');
        Schema::dropIfExists('carpool_profiles');
    }
};

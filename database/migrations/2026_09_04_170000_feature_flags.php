<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature flags, with a global default and a per-country override.
 *
 * Spec §32. This is the first thing Marketplace needs, because everything
 * after it ships switched off: a phase that is not behind a flag is not
 * finished, it is just live.
 *
 * ⛔ THE DEFAULT IS OFF, AND ABSENCE MEANS OFF. A flag nobody has configured
 * for a country must not inherit "on" from anywhere. Spec §32: "Sensitive
 * capability activation must default off in unconfigured countries." Written
 * the other way round - absent means fall back to the global row - a country
 * added later would silently switch on Car Pool before anyone reviewed its
 * transport law. So the global row is a default for the FLAG, and a country
 * row is required before that country sees anything sensitive.
 *
 * Two rows per flag at most:
 *   (key, country_code = null)  the global default
 *   (key, country_code = 'MY')  that country's answer, which wins
 */
return new class extends Migration
{
    /**
     * Spec §32 lists these nine. Seeded off, every one, including in Malaysia:
     * the point of the flag is that turning it on is a decision somebody makes
     * on a day, not a side effect of a deployment.
     */
    private const FLAGS = [
        'marketplace_enabled'          => 'The Marketplace section exists at all',
        'neighbour_offers_enabled'     => 'Neighbour Provider onboarding and offers',
        'individual_sell_enabled'      => 'Occasional personal item sales',
        'business_directory_enabled'   => 'Registered business profiles and listings',
        'professional_services_enabled' => 'Professional profiles and credentials',
        'carpool_enabled'              => 'Car Pool - keep off until the country transport law is reviewed',
        'ratings_enabled'              => 'Ratings and reviews on providers and listings',
        'sponsored_listings_enabled'   => 'Paid placement, labelled',
        'property_listingmine_enabled' => 'Property records pulled from ListingMine',
    ];

    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60);

            // null = the global default for this flag.
            // 'MY' / 'SG' = that country's answer, which overrides the default.
            $t->string('country_code', 2)->nullable();

            $t->boolean('enabled')->default(false);

            // Why it is on or off, for the person who finds it six months later
            // wondering who switched it and what they were waiting for.
            $t->string('note', 300)->nullable();

            $t->unsignedBigInteger('changed_by_admin_id')->nullable();
            $t->timestamps();

            // One row per flag per scope. Postgres treats NULLs as distinct in
            // a unique index, so the global row needs its own partial index or
            // a second global row could be inserted and the two would disagree.
            $t->unique(['key', 'country_code'], 'feature_flags_key_country_unique');
        });

        DB::statement('CREATE UNIQUE INDEX feature_flags_key_global_unique
                       ON feature_flags (key) WHERE country_code IS NULL');

        $now  = now();
        $rows = [];

        foreach (self::FLAGS as $key => $note) {
            $rows[] = [
                'key'          => $key,
                'country_code' => null,
                'enabled'      => false,
                'note'         => $note,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        DB::table('feature_flags')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};

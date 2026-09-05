<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Marketplace category tree, its translations, and its per-country rules.
 * Spec §4.3, §4.4, §20.4, §20.5, §20.6.
 *
 * ⛔ DEVIATION FROM THE SPEC, DELIBERATE, RECORDED HERE AS §0.10 REQUIRES.
 *
 * The spec calls these tables `categories`, `category_translations` and
 * `category_country_rules`. This migration prefixes all three with
 * `marketplace_`, because `categories` already exists and belongs to the NEWS
 * classifier: 21 rows read by CategoryScorer, Taxonomy, TaxonomyController,
 * BrainController and SettingsController, and offered to the model as the list
 * it must choose from when judging a story.
 *
 * Adding Car Pool and Buy & Sell to that table would put them in front of the
 * classifier as valid answers for a news article. The alternative - a
 * content_family discriminator on the shared table - means every one of those
 * five callers must filter correctly forever, and the day one forgets, a news
 * story is filed under Home & Renovation. A separate tree cannot fail that way.
 *
 * The cost is that a category means one thing for news and another for
 * Marketplace, and nothing joins them. That is the correct relationship: they
 * are different taxonomies that happen to share a word.
 */
return new class extends Migration
{
    /**
     * Spec §4.4, seeded exactly. Names are the English defaults; translations
     * live in their own table so a country operator can add Malay and Chinese
     * without a deployment.
     */
    private const TREE = [
        ['property', 'Property', [
            // Property has no children here on purpose: records come from
            // ListingMine and NearbyPost never becomes a second property
            // engine (§27).
        ]],
        ['food_dining', 'Food & Dining', [
            ['restaurants_cafes', 'Restaurants & Cafes'],
            ['home_prepared_food', 'Home-Prepared Food'],
            ['catering', 'Catering'],
            ['menus', 'Menus'],
            ['deals_promotions', 'Deals & Promotions'],
        ]],
        ['home_renovation', 'Home & Renovation', [
            ['renovation', 'Renovation'],
            ['interior_design', 'Interior Design'],
            ['painting', 'Painting'],
            ['cleaning', 'Cleaning'],
            ['plumbing', 'Plumbing'],
            ['air_conditioning', 'Air-Conditioning'],
            ['carpentry_furniture', 'Carpentry & Furniture'],
            ['landscaping', 'Landscaping'],
        ]],
        ['buy_sell', 'Buy & Sell', [
            ['electronics', 'Electronics'],
            ['furniture', 'Furniture'],
            ['household_items', 'Household Items'],
            ['vehicles_accessories', 'Vehicles & Accessories'],
            ['other_personal_items', 'Other Personal Items'],
        ]],
        ['professional_services', 'Professional Services', [
            ['legal', 'Legal'],
            ['accounting_tax', 'Accounting & Tax'],
            ['valuation', 'Valuation'],
            ['medical_healthcare', 'Medical & Healthcare'],
            ['engineering', 'Engineering'],
            ['architecture', 'Architecture'],
            ['financial_insurance', 'Financial & Insurance'],
            ['education', 'Education'],
            ['other_regulated', 'Other Regulated Profession'],
        ]],
        ['car_pool', 'Car Pool', [
            ['seats_available', 'Seats Available'],
            ['looking_for_ride', 'Looking for a Ride'],
        ]],
    ];

    public function up(): void
    {
        Schema::create('marketplace_categories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parent_id')->nullable();

            // Stable across renames and translations. Code refers to this;
            // never to the display name, which an operator may edit.
            $t->string('code', 60)->unique();

            $t->string('default_name', 120);

            // property | food | services | goods | professional | carpool
            $t->string('content_family', 24);

            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index(['parent_id', 'sort_order'], 'mp_categories_tree_idx');
            $t->foreign('parent_id')->references('id')->on('marketplace_categories')->cascadeOnDelete();
        });

        Schema::create('marketplace_category_translations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('category_id');
            $t->string('locale', 8);
            $t->string('name', 120);
            $t->string('description', 400)->nullable();
            $t->timestamps();

            $t->unique(['category_id', 'locale'], 'mp_category_locale_unique');
            $t->foreign('category_id')->references('id')->on('marketplace_categories')->cascadeOnDelete();
        });

        /**
         * What a category means IN ONE COUNTRY. Spec §4.3 and §20.6.
         *
         * ⛔ This table is why there are no category if/else blocks in
         * controllers. Everything that differs by country or category - who may
         * post, what must be verified, which claims are forbidden, how long a
         * listing lives, whether a price may be shown at all - is a row here,
         * not a branch in code. Spec §4.3: "Do not implement category behaviour
         * with large controller if/else blocks."
         *
         * ⛔ AND THE ABSENCE OF A ROW MEANS THE CATEGORY IS OFF IN THAT COUNTRY.
         * Same rule as the feature flags: a category must be turned on for a
         * country deliberately, never by inheriting a default.
         */
        Schema::create('marketplace_category_country_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('category_id');
            $t->string('country_code', 2);
            $t->boolean('enabled')->default(false);

            // ["neighbour_provider","registered_business",...]
            $t->jsonb('allowed_provider_kinds')->nullable();

            // none | phone | identity | registration | credential
            $t->string('minimum_verification_level', 20)->default('none');

            $t->unsignedBigInteger('credential_rule_set_id')->nullable();

            // The structured attributes a listing in this category may carry,
            // as a schema. Validated on write; never trusted raw.
            $t->jsonb('field_schema')->nullable();

            $t->jsonb('moderation_policy')->nullable();
            $t->jsonb('listing_limits')->nullable();
            $t->jsonb('location_policy')->nullable();
            $t->jsonb('pricing_policy')->nullable();
            $t->jsonb('prohibited_claims')->nullable();

            $t->unsignedInteger('default_expiry_days')->default(30);
            $t->boolean('requires_manual_review')->default(false);
            $t->timestamps();

            $t->unique(['category_id', 'country_code'], 'mp_category_country_unique');
            $t->index(['country_code', 'enabled'], 'mp_rules_country_enabled_idx');
            $t->foreign('category_id')->references('id')->on('marketplace_categories')->cascadeOnDelete();
        });

        $this->seedTree();

        // The listings table was created before this one, so its foreign key is
        // added now rather than there.
        Schema::table('marketplace_listings', function (Blueprint $t) {
            $t->foreign('category_id')->references('id')->on('marketplace_categories')->restrictOnDelete();
        });

        Schema::table('provider_categories', function (Blueprint $t) {
            $t->foreign('category_id')->references('id')->on('marketplace_categories')->cascadeOnDelete();
        });
    }

    private function seedTree(): void
    {
        $family = [
            'property' => 'property', 'food_dining' => 'food',
            'home_renovation' => 'services', 'buy_sell' => 'goods',
            'professional_services' => 'professional', 'car_pool' => 'carpool',
        ];

        $now   = now();
        $order = 0;

        foreach (self::TREE as [$code, $name, $children]) {
            $parentId = DB::table('marketplace_categories')->insertGetId([
                'parent_id' => null, 'code' => $code, 'default_name' => $name,
                'content_family' => $family[$code], 'sort_order' => $order += 10,
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $childOrder = 0;

            foreach ($children as [$childCode, $childName]) {
                DB::table('marketplace_categories')->insert([
                    'parent_id' => $parentId, 'code' => $childCode, 'default_name' => $childName,
                    'content_family' => $family[$code], 'sort_order' => $childOrder += 10,
                    'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // ⛔ NO country rules are seeded. Every category is therefore off in
        // every country until somebody enables it deliberately. That is the
        // point: the tree existing is not the same as the tree being open for
        // business, and Car Pool in particular must not become available
        // because a migration ran.
    }

    public function down(): void
    {
        Schema::table('provider_categories', fn (Blueprint $t) => $t->dropForeign(['category_id']));
        Schema::table('marketplace_listings', fn (Blueprint $t) => $t->dropForeign(['category_id']));
        Schema::dropIfExists('marketplace_category_country_rules');
        Schema::dropIfExists('marketplace_category_translations');
        Schema::dropIfExists('marketplace_categories');
    }
};

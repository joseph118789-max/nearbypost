<?php

namespace App\Console\Commands;

use App\Services\Marketplace\CategoryRules;
use App\Services\Marketplace\FoodClaims;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan marketplace:open-categories {country} {--dry}
 *
 * Opens the launch categories for one country, with the rules that category
 * needs there. Spec §4.2, §9, §10.
 *
 * ⛔ THIS IS A DELIBERATE ACT, NOT A MIGRATION. Seeding the tree does not open
 * anything - a category with no country row is closed - and that separation is
 * the point: somebody decides that Food & Dining is open in Malaysia, on a day,
 * having thought about the food-safety wording. Running migrations must never
 * be what opens a market.
 */
class OpenCategories extends Command
{
    protected $signature = 'marketplace:open-categories {country} {--dry : show what would be written}';

    protected $description = 'Open the launch categories for one country, with their rules';

    /**
     * Spec §4.2's launch six, minus Property (ListingMine, Phase 6) and Car
     * Pool (Phase 4, and only after a transport-law review).
     */
    private function rules(): array
    {
        return [
            'home_prepared_food' => [
                'allowed_provider_kinds' => ['neighbour_provider', 'registered_business'],
                'minimum_verification_level' => 'phone',
                'listing_limits'  => ['active_listings' => 3, 'images' => 5],
                'location_policy' => ['allowed_modes' => ['approximate_area', 'service_area']],
                'pricing_policy'  => ['mode' => 'required'],
                'default_expiry_days' => 3,
                'requires_manual_review' => false,

                // Spec §9.1: what a food listing carries beyond the common fields.
                'field_schema' => [
                    'cuisine'        => ['type' => 'string', 'max' => 60],
                    'portion'        => ['type' => 'string', 'max' => 60],
                    'pickup_method'  => ['type' => 'enum', 'values' => ['pickup', 'delivery', 'both']],
                    'halal_state'    => ['type' => 'enum', 'values' => array_keys(FoodClaims::HALAL_STATES)],
                    'allergens'      => ['type' => 'set', 'values' => FoodClaims::ALLERGENS],
                ],

                // ⛔ Spec §9.2 and §18.3. The listing may say the provider is
                // Muslim-owned; it may not say the food is certified unless a
                // certificate has been confirmed.
                'prohibited_claims' => [
                    'halal certified', 'certified halal', 'jakim certified', 'sijil halal',
                    'guaranteed fresh', 'no msg guaranteed',
                ],
                'moderation_policy' => ['ai_first' => true, 'escalate' => ['halal', 'allergen']],
            ],

            'restaurants_cafes' => [
                'allowed_provider_kinds' => ['registered_business'],
                'minimum_verification_level' => 'registration',
                'listing_limits'  => ['active_listings' => 20, 'images' => 10],
                'location_policy' => ['allowed_modes' => ['exact_premises']],
                'pricing_policy'  => ['mode' => 'optional'],
                'default_expiry_days' => 90,
                'prohibited_claims' => ['halal certified', 'certified halal', 'jakim certified'],
            ],

            'catering' => [
                'allowed_provider_kinds' => ['neighbour_provider', 'registered_business'],
                'minimum_verification_level' => 'phone',
                'listing_limits'  => ['active_listings' => 10, 'images' => 8],
                'location_policy' => ['allowed_modes' => ['service_area', 'approximate_area']],
                'pricing_policy'  => ['mode' => 'optional'],
                'default_expiry_days' => 60,
            ],
        ] + $this->homeAndRenovation() + $this->buyAndSell();
    }

    /** Spec §10. */
    private function homeAndRenovation(): array
    {
        $ordinary = [
            'allowed_provider_kinds' => ['neighbour_provider', 'registered_business'],
            'minimum_verification_level' => 'phone',
            'listing_limits'  => ['active_listings' => 5, 'images' => 8],
            'location_policy' => ['allowed_modes' => ['service_area', 'approximate_area', 'exact_premises']],
            'pricing_policy'  => ['mode' => 'optional'],
            'default_expiry_days' => 60,
        ];

        // ⛔ Spec §10.2: an ordinary helper may advertise a leaking tap. They
        // may not advertise anything the law reserves to a licensed person, and
        // the category rule is where that line is drawn rather than in a
        // controller. These are refused outright until a credential rule set
        // exists for the country.
        $regulatedClaims = [
            'licensed electrician', 'wiring installation', 'rewiring',
            'gas installation', 'gas piping',
            'structural alteration', 'demolish wall', 'hack structural',
            'fire system', 'sprinkler installation',
        ];

        return [
            'renovation'      => $ordinary + ['prohibited_claims' => $regulatedClaims, 'requires_manual_review' => true],
            'painting'        => $ordinary,
            'cleaning'        => $ordinary,
            'plumbing'        => $ordinary + ['prohibited_claims' => $regulatedClaims],
            'air_conditioning' => $ordinary + ['prohibited_claims' => ['gas installation', 'gas piping']],
            'carpentry_furniture' => $ordinary,
            'landscaping'     => $ordinary,
            'interior_design' => $ordinary,
        ];
    }

    /** Spec §8.4, §4.4. */
    private function buyAndSell(): array
    {
        $used = [
            'allowed_provider_kinds' => ['neighbour_provider', 'registered_business'],
            'minimum_verification_level' => 'phone',
            'listing_limits'  => ['active_listings' => 10, 'images' => 6],
            'location_policy' => ['allowed_modes' => ['approximate_area', 'online_only']],
            'pricing_policy'  => ['mode' => 'required'],
            'default_expiry_days' => 30,

            // Spec §19.5's conservative starting list, for goods.
            'prohibited_claims' => [
                'replica', 'grade aaa', 'mirror quality', 'oem copy',
                'unlocked icloud', 'bypass activation',
            ],
        ];

        return [
            'electronics'          => $used,
            'furniture'            => $used,
            'household_items'      => $used,
            'vehicles_accessories' => $used + ['requires_manual_review' => true],
            'other_personal_items' => $used,
        ];
    }

    public function handle(): int
    {
        $country = strtoupper((string) $this->argument('country'));

        if (!in_array($country, \App\Services\Geo\SourceCountry::siteCountries(), true)) {
            $this->error("{$country} is not one of the countries this site serves.");

            return self::FAILURE;
        }

        $dry     = (bool) $this->option('dry');
        $written = 0;
        $skipped = 0;

        foreach ($this->rules() as $code => $rule) {
            $categoryId = DB::table('marketplace_categories')->where('code', $code)->value('id');

            if ($categoryId === null) {
                $this->warn("  no such category: {$code}");
                $skipped++;

                continue;
            }

            $row = [
                'category_id'  => $categoryId,
                'country_code' => $country,
                'enabled'      => true,
                'allowed_provider_kinds' => json_encode($rule['allowed_provider_kinds']),
                'minimum_verification_level' => $rule['minimum_verification_level'],
                'field_schema'      => isset($rule['field_schema']) ? json_encode($rule['field_schema']) : null,
                'moderation_policy' => isset($rule['moderation_policy']) ? json_encode($rule['moderation_policy']) : null,
                'listing_limits'    => json_encode($rule['listing_limits']),
                'location_policy'   => json_encode($rule['location_policy']),
                'pricing_policy'    => json_encode($rule['pricing_policy']),
                'prohibited_claims' => isset($rule['prohibited_claims']) ? json_encode($rule['prohibited_claims']) : null,
                'default_expiry_days' => $rule['default_expiry_days'],
                'requires_manual_review' => $rule['requires_manual_review'] ?? false,
                'updated_at'   => now(),
                'created_at'   => now(),
            ];

            $this->line(sprintf('  %-22s %-38s %2d days, %s',
                $code,
                implode('/', $rule['allowed_provider_kinds']),
                $rule['default_expiry_days'],
                ($rule['requires_manual_review'] ?? false) ? 'a person reads every one' : 'AI first'));

            if (!$dry) {
                DB::table('marketplace_category_country_rules')->updateOrInsert(
                    ['category_id' => $categoryId, 'country_code' => $country],
                    $row
                );
            }

            $written++;
        }

        CategoryRules::forget();

        $this->newLine();
        $this->info($dry
            ? "{$written} categories would be opened in {$country}. Nothing was written."
            : "{$written} categories opened in {$country}.");

        if ($skipped > 0) {
            $this->warn("{$skipped} were not found in the tree.");
        }

        // ⛔ Opening a category does NOT open the marketplace. The flag is a
        // separate decision, and saying so here stops somebody running this and
        // wondering why the site looks unchanged.
        $this->newLine();
        $this->line('The marketplace_enabled flag is separate, and still governs whether any of this is visible.');

        return self::SUCCESS;
    }
}

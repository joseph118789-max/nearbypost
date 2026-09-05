<?php

namespace App\Services\Marketplace;

use App\Services\Flags\FeatureFlags;
use Illuminate\Support\Facades\DB;

/**
 * What a reader sees when they open the Marketplace tab.
 *
 * The six sections come from `marketplace_categories`, not from a list written
 * here, so a category added or renamed in the admin appears on the site without
 * anyone editing a template (spec 4.3 - no if/else ladders of categories in
 * code). Their children become the chips inside each section.
 *
 * ⛔ WHILE A SECTION IS CLOSED IT SHOWS EXAMPLES, AND SAYS SO ON EVERY CARD.
 * Nothing real exists yet, and an empty page tells a reader nothing about what
 * this will be. Made-up listings do - as long as it is impossible to mistake
 * one for a business that exists. See SampleListings: they are not in the
 * database, they carry a label, and they stop appearing the moment a section is
 * switched on for real.
 */
class MarketplaceBrowse
{
    /**
     * The flag each section waits on. A section with no flag of its own rides
     * on marketplace_enabled.
     */
    private const SECTION_FLAG = [
        'food_dining'           => 'neighbour_offers_enabled',
        'home_renovation'       => 'business_directory_enabled',
        'buy_sell'              => 'individual_sell_enabled',
        'professional_services' => 'professional_services_enabled',
        'car_pool'              => 'carpool_enabled',
        'property'              => 'property_listingmine_enabled',
    ];

    /** One line saying what the section is for, in a reader's words. */
    private const SECTION_BLURB = [
        'food_dining'           => 'Home kitchens, warungs and cafes near you, and what they are serving today.',
        'home_renovation'       => 'Aircon, plumbing, carpentry, cleaning — the trades people ask their neighbours about.',
        'buy_sell'              => 'Things people nearby are selling. Collection is usually a short walk.',
        'professional_services' => 'Licensed professionals, with the credential checked and named rather than summarised.',
        'car_pool'              => 'Lifts people were already making. A noticeboard, not a taxi service.',
        'property'              => 'Homes for sale and rent near you, listed and answered on ListingMine.',
    ];

    /**
     * The six sections, each with its children and its state.
     *
     * @return list<array<string, mixed>>
     */
    public static function sections(string $country): array
    {
        $country = strtoupper($country);

        $rows = DB::table('marketplace_categories')
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'default_name']);

        $children = [];

        foreach ($rows as $r) {
            if ($r->parent_id !== null) {
                $children[(int) $r->parent_id][] = $r;
            }
        }

        $out = [];

        foreach ($rows as $r) {
            if ($r->parent_id !== null) {
                continue;
            }

            $kids = $children[(int) $r->id] ?? [];
            $open = self::isOpen($r->code, $country);

            $out[] = [
                'code'     => $r->code,
                'name'     => $r->default_name,
                'blurb'    => self::SECTION_BLURB[$r->code] ?? '',
                'children' => array_map(fn ($c) => $c->default_name, $kids),
                'open'     => $open,

                // Real listings, counted with the same predicate the search
                // uses, so this number and that page cannot disagree.
                'live'     => $open ? self::liveCount((int) $r->id, $country) : 0,
                'samples'  => $open ? [] : SampleListings::forSection($r->code),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function section(string $code, string $country): ?array
    {
        foreach (self::sections($country) as $s) {
            if ($s['code'] === $code) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Is this section actually open to readers here?
     *
     * ⛔ BOTH gates, not either. marketplace_enabled is the module; the
     * section's own flag is the capability. A section can never be reachable
     * because somebody switched on the module and forgot the rest.
     */
    public static function isOpen(string $code, string $country): bool
    {
        if (FeatureFlags::off('marketplace_enabled', $country)) {
            return false;
        }

        $flag = self::SECTION_FLAG[$code] ?? null;

        return $flag === null || FeatureFlags::on($flag, $country);
    }

    private static function liveCount(int $sectionId, string $country): int
    {
        $ids = DB::table('marketplace_categories')
            ->where('id', $sectionId)->orWhere('parent_id', $sectionId)
            ->pluck('id')->all();

        return ListingSearch::visible()
            ->where('l.country_code', $country)
            ->whereIn('l.category_id', $ids)
            ->count();
    }
}

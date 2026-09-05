<?php

namespace App\Services\Marketplace;

/**
 * Made-up listings, so the Marketplace can be looked at before it has anything
 * real in it.
 *
 * ⛔⛔ THESE ARE NOT IN THE DATABASE, AND THAT IS THE POINT.
 *
 * Seeding invented businesses into `marketplace_listings` would put them one
 * forgotten flag away from a reader's search results, a sitemap, or a set of
 * structured data telling Google that a shop exists which does not. They live
 * in code instead: they cannot be searched, cannot be indexed, cannot be
 * contacted, and deleting this one file removes every trace of them.
 *
 * ⛔ EVERY CARD BUILT FROM THIS IS LABELLED "Example". A page of realistic
 * listings with no label is indistinguishable from a page of real ones - the
 * same reasoning as the sponsored-placement label in spec 15.4. The phone
 * numbers are the 555-style reserved range and the names are obviously
 * invented, so nobody is called by mistake.
 *
 * They are shown ONLY while the Marketplace is closed. The day
 * marketplace_enabled is switched on for a country, real listings take over and
 * these disappear on their own - see MarketplaceBrowse::samplesFor().
 */
class SampleListings
{
    public const LABEL = 'Example';

    /**
     * @return array<string, list<array<string, mixed>>> keyed by section code
     */
    public static function all(): array
    {
        return [
            'food_dining' => [
                [
                    'title'    => 'Nasi lemak, fresh every morning',
                    'provider' => 'Kak Yah\'s Kitchen',
                    'child'    => 'Home-Prepared Food',
                    'price'    => 'RM6.00',
                    'distance' => '0.4 km',
                    'area'     => 'Setapak, Kuala Lumpur',
                    'note'     => 'Ready from 6.30am. Message the night before for more than five packets.',
                    'badge'    => 'Phone confirmed',
                ],
                [
                    'title'    => 'Set lunch, two courses',
                    'provider' => 'Warung Seri Mutiara',
                    'child'    => 'Restaurants & Cafes',
                    'price'    => 'RM12.90',
                    'distance' => '1.1 km',
                    'area'     => 'Wangsa Maju, Kuala Lumpur',
                    'note'     => 'Weekdays 11.30am to 2.30pm. Dine in or take away.',
                    'badge'    => 'Business registration confirmed',
                ],
                [
                    'title'    => 'Kuih order for events',
                    'provider' => 'Puan Salmah',
                    'child'    => 'Catering',
                    'price'    => 'From RM45 a tray',
                    'distance' => '2.3 km',
                    'area'     => 'Gombak, Selangor',
                    'note'     => 'Three days\' notice. Delivery within Klang Valley by arrangement.',
                    'badge'    => 'Phone confirmed',
                ],
            ],

            'home_renovation' => [
                [
                    'title'    => 'Aircon service and gas top-up',
                    'provider' => 'CoolAir Servis',
                    'child'    => 'Air-Conditioning',
                    'price'    => 'RM80 a unit',
                    'distance' => '1.8 km',
                    'area'     => 'Setapak, Kuala Lumpur',
                    'note'     => 'Same-day slots most weekdays. Chemical wash quoted separately.',
                    'badge'    => 'Business registration confirmed',
                ],
                [
                    'title'    => 'Kitchen cabinet, made to measure',
                    'provider' => 'Lim Carpentry Works',
                    'child'    => 'Carpentry & Furniture',
                    'price'    => 'Quoted after a site visit',
                    'distance' => '3.6 km',
                    'area'     => 'Ampang, Selangor',
                    'note'     => 'Free measurement within 10 km. Roughly three weeks from deposit.',
                    'badge'    => 'Business registration confirmed',
                ],
                [
                    'title'    => 'Move-out cleaning, whole unit',
                    'provider' => 'Bersih Rapi',
                    'child'    => 'Cleaning',
                    'price'    => 'From RM280',
                    'distance' => '0.9 km',
                    'area'     => 'Danau Kota, Kuala Lumpur',
                    'note'     => 'Two cleaners, about four hours for a three-room flat.',
                    'badge'    => 'Phone confirmed',
                ],
            ],

            'buy_sell' => [
                [
                    'title'    => 'Baby cot with mattress',
                    'provider' => 'Nurul',
                    'child'    => 'Furniture',
                    'price'    => 'RM180',
                    'distance' => '0.6 km',
                    'area'     => 'Setapak, Kuala Lumpur',
                    'note'     => 'Used eight months. Collection only, ground floor.',
                    'badge'    => 'Phone confirmed',
                ],
                [
                    'title'    => 'Office chair, mesh back',
                    'provider' => 'Faizal',
                    'child'    => 'Furniture',
                    'price'    => 'RM120',
                    'distance' => '1.4 km',
                    'area'     => 'Wangsa Maju, Kuala Lumpur',
                    'note'     => 'One of two, both the same. Gas lift works.',
                    'badge'    => 'Phone confirmed',
                ],
                [
                    'title'    => 'Bicycle, 26-inch',
                    'provider' => 'Ravi',
                    'child'    => 'Vehicles & Accessories',
                    'price'    => 'RM250',
                    'distance' => '2.0 km',
                    'area'     => 'Gombak, Selangor',
                    'note'     => 'New tyres last month. Basket included.',
                    'badge'    => 'Phone confirmed',
                ],
            ],

            'professional_services' => [
                [
                    'title'    => 'Conveyancing for a first home',
                    'provider' => 'Tan &amp; Partners',
                    'child'    => 'Legal',
                    'price'    => 'Scale fees',
                    'distance' => '4.2 km',
                    'area'     => 'Kuala Lumpur',
                    'note'     => 'Sale and purchase, loan documentation, stamping.',
                    'badge'    => 'Professional credential confirmed',
                ],
                [
                    'title'    => 'Personal and small business tax filing',
                    'provider' => 'Siti Rahmah &amp; Co.',
                    'child'    => 'Accounting & Tax',
                    'price'    => 'From RM350',
                    'distance' => '2.7 km',
                    'area'     => 'Setapak, Kuala Lumpur',
                    'note'     => 'LHDN e-filing, book-keeping by arrangement.',
                    'badge'    => 'Professional credential confirmed',
                ],
                [
                    'title'    => 'House renovation drawings and submission',
                    'provider' => 'Studio Reka',
                    'child'    => 'Architecture',
                    'price'    => 'Quoted per project',
                    'distance' => '5.8 km',
                    'area'     => 'Kuala Lumpur',
                    'note'     => 'Local authority submission included.',
                    'badge'    => 'Professional credential confirmed',
                ],
            ],

            'car_pool' => [
                [
                    'title'    => 'Setapak to KL Sentral, weekday mornings',
                    'provider' => 'Azman',
                    'child'    => 'Seats Available',
                    'price'    => 'Sharing running costs',
                    'distance' => '0.7 km',
                    'area'     => 'Leaves around 7.15am',
                    'note'     => 'Two seats. Non-smoking car. Journey I make anyway.',
                    'badge'    => 'Identity confirmed',
                ],
                [
                    'title'    => 'Looking for a ride to Cyberjaya',
                    'provider' => 'Mei Ling',
                    'child'    => 'Looking for a Ride',
                    'price'    => 'Happy to share petrol and toll',
                    'distance' => '1.2 km',
                    'area'     => 'Around 7.30am, flexible half an hour',
                    'note'     => 'One passenger, Monday to Thursday.',
                    'badge'    => 'Identity confirmed',
                ],
            ],

            'property' => [
                [
                    'title'    => '3-bedroom condominium, Mont Kiara',
                    'provider' => 'Listed on ListingMine',
                    'child'    => 'For sale',
                    'price'    => 'RM950,000',
                    'distance' => '6.4 km',
                    'area'     => '1,450 sq ft · 3 bed · 2 bath',
                    'note'     => 'Enquiries are handled on ListingMine.',
                    'badge'    => 'Powered by ListingMine',
                ],
                [
                    'title'    => 'Terrace house for rent, Petaling Jaya',
                    'provider' => 'Listed on ListingMine',
                    'child'    => 'For rent',
                    'price'    => 'RM2,800 a month',
                    'distance' => '11.2 km',
                    'area'     => '4 bed · 3 bath',
                    'note'     => 'Enquiries are handled on ListingMine.',
                    'badge'    => 'Powered by ListingMine',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function forSection(string $code): array
    {
        return self::all()[$code] ?? [];
    }
}

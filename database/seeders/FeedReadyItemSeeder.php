<?php

namespace Database\Seeders;

use App\Models\FeedReadyItem;
use Illuminate\Database\Seeder;

class FeedReadyItemSeeder extends Seeder
{
    public function run(): void
    {
        $cats = ['property','transport','crime','sports','business','lifestyle','technology','health','education','environment'];
        $subcats = [
            'property'=>['property_news','property_analysis','property_condo'],
            'transport'=>['transport_news','traffic_updates','transport_accidents'],
            'crime'=>['crime_news','theft','fraud'],
            'sports'=>['badminton','football','basketball'],
            'business'=>['business_news','startup','markets'],
            'lifestyle'=>['lifestyle_news','food','community_events'],
            'technology'=>['tech_news','ai','gadgets'],
            'health'=>['health_news','hospitals','wellness'],
            'education'=>['education_news','universities','scholarships'],
            'environment'=>['environment_news','weather','climate']
        ];
        $locs = [
            ['name'=>'Kuala Lumpur','lat'=>3.1390,'lng'=>101.6869],
            ['name'=>'Petaling Jaya','lat'=>3.1076,'lng'=>101.5932],
            ['name'=>'Shah Alam','lat'=>3.0733,'lng'=>101.5183],
            ['name'=>'Subang Jaya','lat'=>3.0448,'lng'=>101.5356],
            ['name'=>'Johor Bahru','lat'=>1.4927,'lng'=>103.7384],
            ['name'=>'Penang','lat'=>5.4141,'lng'=>100.3288],
            ['name'=>'Ipoh','lat'=>4.5975,'lng'=>101.0901],
            ['name'=>'Kota Kinabalu','lat'=>5.9804,'lng'=>116.0733],
        ];
        $sources = ['The Star','Malay Mail','The Edge','Bernama','NST','Business Times','Malaysian Reserve'];
        $headlines = [
            'property'=>['Condo launch draws strong weekend turnout in Petaling Jaya','Shah Alam property prices surge 12% in Q1','New MRT station boosts Subang Jaya property demand','Senior living complex planned for Kuala Lumpur city centre'],
            'transport'=>['Burst pipe causes traffic jam near SS2, Petaling Jaya','Flash floods hit Klang Valley routes after heavy storm','New express bus service connects KL to Johor Bahru','LRT extension to Shah Alam gets final approval'],
            'crime'=>['Police bust syndicate operating in Klang Valley','Online fraud cases rise 30% in past quarter','Theft ring targeting condos in Petaling Jaya arrested','Identity theft cases surge during festive season'],
            'sports'=>['Malaysia Open badminton: Local pair set for key clash','KL City FC extend winning streak to five matches','Young swimmer from Johor breaks national record','Basketball tournament returns to Shah Alam after 3-year hiatus'],
            'business'=>['Tech startup from Subang Jaya secures Series A funding','Small business grants now available in Kuala Lumpur','Foreign investment in Malaysia reaches 5-year high','KL businesses report strongest quarterly growth'],
            'lifestyle'=>['Ramadan bazaar at TTDI extended until 10pm','New food court opens in Petaling Jaya with 30 vendors','Community garden project launched in Shah Alam','Weekend markets in Kuala Lumpur draw record crowds'],
            'technology'=>['AI company in KL announces 100 new jobs','Tech hub in Subang Jaya expands campus','Smart city sensors deployed across Kuala Lumpur','Cyber security training program launched for SMEs'],
            'health'=>['New hospital wing opens in Petaling Jaya','Health Ministry launches diabetes awareness campaign','Kuala Lumpur ranks among top cities for air quality','Mental health support services expanded in schools'],
            'education'=>['University in Kuala Lumpur climbs global rankings','New technical institute to open in Johor Bahru','Student scholarship program expanded for B40 families','School in Shah Alam wins national science competition'],
            'environment'=>['Haze advisory issued for Klang Valley','Turtle conservation project succeeds in Penang','Recycling program launches in Petaling Jaya apartments','Kuala Lumpur aims for 50% green space coverage']
        ];
        $precisions = ['exact_area','approximate_area','state_center','region'];
        $modes = ['hybrid','location_only','category_only'];

        FeedReadyItem::truncate();

        for ($i = 0; $i < 40; $i++) {
            $cat = $cats[array_rand($cats)];
            $subs = $subcats[$cat] ?? ['news'];
            $loc = $locs[array_rand($locs)];
            $titles = $headlines[$cat] ?? ['News update for '.$cat];
            $title = $titles[array_rand($titles)];
            $h = rand(1, 168);
            $urlSlug = $cat . '-' . $i . '-' . time();
            // First create a news_item to get an ID
            $newsItem = \App\Models\NewsItem::create([
                'title' => $title,
                'summary' => 'Authoritative coverage of this developing story.',
                'source' => $sources[array_rand($sources)],
                'url' => 'https://example.com/news/' . $urlSlug,
                'published_at' => now()->subHours($h),
                'primary_category' => $cat,
                'secondary_category' => $subs[array_rand($subs)],
                'main_place_text' => $loc['name'],
                'lat' => $loc['lat'] + (rand(-50, 50) / 1000),
                'lng' => $loc['lng'] + (rand(-50, 50) / 1000),
                'precision_type' => $precisions[array_rand($precisions)],
                'relevance_mode' => $modes[array_rand($modes)],
            ]);
            FeedReadyItem::create([
                'news_item_id' => $newsItem->id,
                'title' => $title,
                'summary' => 'Authorities and stakeholders are monitoring the situation closely. Experts suggest the impact could be significant for residents and businesses in the area over the coming weeks.',
                'source' => $sources[array_rand($sources)],
                'url' => 'https://example.com/news/' . $urlSlug,
                'published_at' => now()->subHours($h),
                'is_active' => true,
                'primary_category' => $cat,
                'secondary_category' => $subs[array_rand($subs)],
                'location_label' => $loc['name'],
                'lat' => $loc['lat'] + (rand(-50, 50) / 1000),
                'lng' => $loc['lng'] + (rand(-50, 50) / 1000),
                'precision_type' => $precisions[array_rand($precisions)],
                'relevance_mode' => $modes[array_rand($modes)],
            ]);
        }

        $this->command->info('Seeded ' . FeedReadyItem::count() . ' feed items');
    }
}

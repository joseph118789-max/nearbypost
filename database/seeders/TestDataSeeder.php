<?php

namespace Database\Seeders;

use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $cats = ['property','sports','transport','technology','health','business','crime','education','environment'];
        $locs = ['Kuala Lumpur','Petaling Jaya','Singapore','Bangkok','Johor Bahru','Penang','Shah Alam','Subang Jaya'];
        $sources = ['The Star','Malay Mail','Business Times','Channel News Asia','Tech in Asia'];

        for ($i = 0; $i < 25; $i++) {
            NewsItem::create([
                'title' => 'Sample News Headline ' . ($i + 1),
                'summary' => 'This is a sample news summary for testing the admin panel.',
                'primary_category' => $cats[array_rand($cats)],
                'secondary_category' => 'news',
                'status' => $i < 20 ? 'active' : 'inactive',
                'relevance_mode' => ['hybrid', 'location_only', 'category_only'][array_rand(['hybrid', 'location_only', 'category_only'])],
                'precision_type' => ['exact_area', 'approximate_area', 'state_center'][array_rand(['exact_area', 'approximate_area', 'state_center'])],
                'main_place_text' => $locs[array_rand($locs)],
                'lat' => number_format(3.1 + (rand(-50, 50) / 100), 7, '.', ''),
                'lng' => number_format(101.7 + (rand(-50, 50) / 100), 7, '.', ''),
                'source' => $sources[array_rand($sources)],
                'url' => 'https://example.com/news/' . ($i + 1) . '?t=' . time(),
                'published_at' => now()->subDays(rand(0, 30))->subHours(rand(0, 23)),
            ]);
        }

        $groups = ['Main Group', 'Premium Alerts', 'Regional News', 'VIP Updates'];
        for ($i = 0; $i < 15; $i++) {
            $code = 'USER_' . strtoupper(substr(md5($i), 0, 6));
            User::create([
                'name' => $code,
                'email' => 'user' . $i . '@nearbypost.local',
                'user_code' => $code,
                'mobile' => '+60' . rand(10, 99) . ' ' . rand(100, 999) . ' ' . rand(100, 999),
                'wa_group' => $groups[array_rand($groups)],
                'interest_sub_cat' => $cats[array_rand($cats)] . '_news',
                'status' => $i < 12 ? 'active' : 'inactive',
                'location_name' => $locs[array_rand($locs)],
                'join_date' => now()->subDays(rand(1, 60)),
                'password' => bcrypt('password'),
            ]);
        }
    }
}

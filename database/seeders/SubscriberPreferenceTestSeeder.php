<?php

namespace Database\Seeders;

use App\Models\Subscriber;
use Illuminate\Database\Seeder;

class SubscriberPreferenceTestSeeder extends Seeder
{
    public function run(): void
    {
        Subscriber::create([
            'name' => 'Test Prefs User',
            'phone' => '+15551234567',
            'status' => 'active',
            'preferred_categories' => ['news', 'sports', 'weather'],
            'alert_radius_km' => 25.0,
            'location_lat' => 40.7128,
            'location_lng' => -74.0060,
            'notification_frequency' => 'daily',
        ]);

        Subscriber::create([
            'name' => 'NYC User',
            'phone' => '+15559876543',
            'status' => 'active',
            'preferred_categories' => ['crime', 'politics'],
            'alert_radius_km' => 10.0,
            'location_lat' => 40.7580,
            'location_lng' => -73.9855,
            'notification_frequency' => 'immediate',
        ]);

        Subscriber::create([
            'name' => 'No Prefs User',
            'phone' => '+15551112222',
            'status' => 'active',
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\RelevanceScore;
use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to calculate relevance scores for feed items
 * 
 * Usage: php artisan feed:calculate-scores [--user_id=N]
 * 
 * Calculates relevance scores based on:
 * - Distance decay (closer = higher)
 * - Category affinity (user's preferred categories)
 * - Freshness decay (newer = higher)
 * - Authority signal (known sources)
 */
class CalculateFeedScores extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feed:calculate-scores 
                            {--user_id= : Calculate scores for a specific user ID}
                            {--batch=100 : Number of items to process per batch}
                            {--dry-run : Preview scores without saving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate relevance scores for feed items per user';

    /**
     * Default preferred categories for users without preferences
     */
    protected array $defaultCategories = ['property', 'business', 'technology'];

    /**
     * Max distance in km to consider for scoring
     */
    protected float $maxDistance = 50.0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->option('user_id');
        $batchSize = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');

        $this->info('🚀 Starting relevance score calculation...');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No scores will be saved');
        }

        // Get users to process
        $users = $this->getUsers($userId);
        
        if ($users->isEmpty()) {
            $this->error('❌ No users found to process');
            return Command::FAILURE;
        }

        $this->info("📊 Processing {$users->count()} user(s)");

        // Get news items to score (feed_ready items from D15)
        $newsItems = $this->getNewsItems();
        
        if ($newsItems->isEmpty()) {
            $this->error('❌ No feed-ready news items found');
            return Command::FAILURE;
        }

        $this->info("📰 Found {$newsItems->count()} feed-ready items to score");

        // Process each user
        $totalProcessed = 0;
        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($users as $user) {
            $this->line("\n👤 Processing user ID: {$user->id} ({$user->name})");

            // Get user's preferences (location and categories)
            $userPreferences = $this->getPreferences($user);
            
            $userProcessed = 0;
            $userCreated = 0;
            $userUpdated = 0;

            // Process in batches
            $newsItemsChunked = $newsItems->chunk($batchSize);
            
            foreach ($newsItemsChunked as $chunk) {
                foreach ($chunk as $item) {
                    // Calculate distance between user and news item location
                    $distance = $this->calculateDistance(
                        $userPreferences['lat'],
                        $userPreferences['lng'],
                        $item->latitude ?? null,
                        $item->longitude ?? null
                    );

                    // Skip if too far (beyond maxDistance)
                    if ($distance > $this->maxDistance && $this->maxDistance > 0) {
                        continue;
                    }

                    // Calculate individual score components
                    $distanceScore = RelevanceScore::calculateDistanceScore($distance);
                    $categoryScore = RelevanceScore::calculateCategoryScore(
                        $item->ai_category ?? $item->category ?? 'general',
                        $userPreferences['preferred_categories']
                    );
                    $freshnessScore = RelevanceScore::calculateFreshnessScore($item->published_at);
                    $authorityScore = RelevanceScore::calculateAuthorityScore($item->source ?? 'unknown');

                    // Build breakdown
                    $breakdown = [
                        'distance' => round($distanceScore, 4),
                        'distance_km' => round($distance, 2),
                        'category' => round($categoryScore, 4),
                        'category_match' => $item->ai_category ?? $item->category ?? 'general',
                        'freshness' => round($freshnessScore, 4),
                        'published_at' => $item->published_at?->toISOString(),
                        'authority' => round($authorityScore, 4),
                        'source' => $item->source ?? 'unknown',
                        'weights' => [
                            'distance' => RelevanceScore::WEIGHT_DISTANCE,
                            'category' => RelevanceScore::WEIGHT_CATEGORY,
                            'freshness' => RelevanceScore::WEIGHT_FRESHNESS,
                            'authority' => RelevanceScore::WEIGHT_AUTHORITY,
                        ],
                    ];

                    // Calculate total score
                    $totalScore = RelevanceScore::calculateTotalScore($breakdown);

                    if ($dryRun) {
                        $this->line("  📄 {$item->id}: {$item->title} (score: " . round($totalScore, 4) . ")");
                    } else {
                        // Upsert the score
                        $result = RelevanceScore::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'news_item_id' => $item->id,
                            ],
                            [
                                'score' => round($totalScore, 4),
                                'score_breakdown' => $breakdown,
                            ]
                        );

                        if ($result->wasRecentlyCreated) {
                            $userCreated++;
                        } else {
                            $userUpdated++;
                        }
                    }

                    $userProcessed++;
                    $totalProcessed++;
                }
            }

            $this->info("  ✅ Scored {$userProcessed} items (created: {$userCreated}, updated: {$userUpdated})");
            $totalCreated += $userCreated;
            $totalUpdated += $userUpdated;
        }

        // Summary
        $this->line("\n" . str_repeat('=', 50));
        $this->info('📈 SUMMARY');
        $this->info("  Total items processed: {$totalProcessed}");
        
        if ($dryRun) {
            $this->warn('  (dry-run - no records saved)');
        } else {
            $this->info("  Records created: {$totalCreated}");
            $this->info("  Records updated: {$totalUpdated}");
        }
        
        $this->info('✨ Done!');

        return Command::SUCCESS;
    }

    /**
     * Get users to process
     */
    protected function getUsers($userId)
    {
        if ($userId) {
            return Subscriber::where('id', $userId)->where('status', 'active')->get();
        }
        
        return Subscriber::active()->get();
    }

    /**
     * Get feed-ready news items
     * 
     * This queries news_items table with feed_ready status.
     * Adjust the query based on actual table structure.
     */
    protected function getNewsItems()
    {
        // Try to query news_items table
        // If table doesn't exist, return empty collection
        
        try {
            // Check if news_items table exists
            if (!DB::getSchemaBuilder()->hasTable('news_items')) {
                $this->warn('⚠️ news_items table not found, using mock data for testing');
                return $this->getMockNewsItems();
            }

            return DB::table('news_items')
                ->where('feed_ready', true)
                ->orderBy('published_at', 'desc')
                ->get();
        } catch (\Exception $e) {
            $this->warn('⚠️ Could not query news_items: ' . $e->getMessage());
            return $this->getMockNewsItems();
        }
    }

    /**
     * Get mock news items for testing when table doesn't exist
     */
    protected function getMockNewsItems()
    {
        // Return sample data structure for testing
        return collect([
            (object) [
                'id' => 1,
                'title' => 'Property market shows growth in KL',
                'source' => 'the_star',
                'category' => 'property',
                'ai_category' => 'property',
                'published_at' => now()->subHours(2),
                'latitude' => 3.1390,
                'longitude' => 101.6869,
            ],
            (object) [
                'id' => 2,
                'title' => 'New tech hub opening in Cyberjaya',
                'source' => 'malay_mail',
                'category' => 'technology',
                'ai_category' => 'technology',
                'published_at' => now()->subHours(5),
                'latitude' => 2.9213,
                'longitude' => 101.6559,
            ],
            (object) [
                'id' => 3,
                'title' => 'Business summit in Kuala Lumpur',
                'source' => 'bbc_news',
                'category' => 'business',
                'ai_category' => 'business',
                'published_at' => now()->subHours(12),
                'latitude' => 3.1390,
                'longitude' => 101.6869,
            ],
        ]);
    }

    /**
     * Get user preferences (location and categories)
     */
    protected function getPreferences($user): array
    {
        // Check for user preferences in subscriber table
        // This could be extended to have a separate user_preferences table
        
        return [
            'lat' => $user->latitude ?? 3.1390, // Default: Kuala Lumpur
            'lng' => $user->longitude ?? 101.6869,
            'preferred_categories' => $user->preferred_categories 
                ? json_decode($user->preferred_categories, true) 
                : $this->defaultCategories,
            'max_distance' => $user->max_distance_km ?? $this->maxDistance,
        ];
    }

    /**
     * Calculate distance between two points using Haversine formula
     * 
     * @param float $lat1 User's latitude
     * @param float $lng1 User's longitude  
     * @param float|null $lat2 Item's latitude (null if unavailable)
     * @param float|null $lng2 Item's longitude (null if unavailable)
     * @return float Distance in kilometers
     */
    protected function calculateDistance(float $lat1, float $lng1, ?float $lat2, ?float $lng2): float
    {
        // If no coordinates for item, return middle value
        if ($lat2 === null || $lng2 === null) {
            return $this->maxDistance / 2;
        }

        $earthRadius = 6371; // km

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLng / 2) * sin($deltaLng / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
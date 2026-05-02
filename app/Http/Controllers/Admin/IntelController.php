<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\FeedReadyItem;
use App\Models\NewsItem;
use App\Models\User as Subscriber;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IntelController extends Controller
{
    public function analytics(): JsonResponse
    {
        $cacheKey = "admin_intel_analytics_v2";
        $data = Cache::get($cacheKey);
        if ($data) return response()->json(["success" => true, "data" => $data, "cached" => true]);

        // Use FeedReadyItem as primary news source (same as front-end)
        $feedQuery = FeedReadyItem::where('is_active', true)
            ->where('relevance_mode', '!=', 'category_only');

        $totalUsers = Subscriber::count();
        $activeToday = Subscriber::whereDate("join_date", Carbon::today())->count();
        $totalClicks = (int) DB::selectOne("SELECT COALESCE(SUM(click_count), 0) as total FROM news_items")->total ?? 0;

        // Top category from feed (front-end matches this)
        $topCategoryRow = DB::selectOne("
            SELECT primary_category, COUNT(*) as count
            FROM feed_ready_items
            WHERE is_active = true AND relevance_mode != 'category_only' AND primary_category IS NOT NULL AND primary_category != ''
            GROUP BY primary_category
            ORDER BY COUNT(*) DESC LIMIT 1
        ");
        $topCategory = $topCategoryRow ? $topCategoryRow->primary_category : null;

        // Peak hour from feed
        $peakHourRow = DB::selectOne("
            SELECT EXTRACT(HOUR FROM published_at) as hour, COUNT(*) as count
            FROM feed_ready_items
            WHERE is_active = true AND relevance_mode != 'category_only' AND published_at IS NOT NULL
            GROUP BY EXTRACT(HOUR FROM published_at)
            ORDER BY COUNT(*) DESC LIMIT 1
        ");
        $peakHour = $peakHourRow ? (int) $peakHourRow->hour : 9;

        // Location analytics - join feed with news_items for click data
        $locationAnalytics = DB::select("
            SELECT
                fri.location_label as location,
                COUNT(DISTINCT fri.news_item_id) as news_count,
                COALESCE(SUM(ni.click_count), 0) as click_count,
                fri.primary_category as top_category
            FROM feed_ready_items fri
            LEFT JOIN news_items ni ON ni.id = fri.news_item_id
            WHERE fri.is_active = true
              AND fri.relevance_mode != 'category_only'
              AND fri.location_label IS NOT NULL
              AND fri.location_label != ''
            GROUP BY fri.location_label, fri.primary_category
            ORDER BY news_count DESC
            LIMIT 10
        ");
        $locationAnalytics = array_map(fn($r) => [
            'location' => $r->location,
            'count' => (int) $r->news_count,
            'click_count' => (int) $r->click_count,
            'top_category' => $r->top_category,
        ], $locationAnalytics);

        // Category distribution from feed
        $categoryDistribution = DB::select("
            SELECT primary_category, secondary_category, COUNT(*) as count
            FROM feed_ready_items
            WHERE is_active = true AND relevance_mode != 'category_only'
              AND primary_category IS NOT NULL AND primary_category != ''
            GROUP BY primary_category, secondary_category
            ORDER BY COUNT(*) DESC
        ");
        $categoryDistribution = array_map(fn($r) => [
            'category' => $r->primary_category,
            'count' => (int) $r->count,
        ], $categoryDistribution);

        // Top news - join to get click_count
        $topNews = DB::select("
            SELECT
                fri.id, fri.news_item_id, fri.title,
                COALESCE(ni.click_count, 0) as clicks,
                fri.published_at, fri.primary_category
            FROM feed_ready_items fri
            LEFT JOIN news_items ni ON ni.id = fri.news_item_id
            WHERE fri.is_active = true AND fri.relevance_mode != 'category_only'
            ORDER BY COALESCE(ni.click_count, 0) DESC
            LIMIT 10
        ");
        $topNews = array_map(fn($r) => [
            'id' => $r->id,
            'news_item_id' => $r->news_item_id,
            'title' => $r->title,
            'clicks' => (int) $r->clicks,
            'published_at' => $r->published_at,
            'primary_category' => $r->primary_category,
        ], $topNews);

        // Time series - publications per day over last 30 days
        $timeSeriesData = DB::select("
            SELECT DATE(published_at) as date, COUNT(*) as count
            FROM feed_ready_items
            WHERE is_active = true AND relevance_mode != 'category_only'
              AND published_at >= NOW() - INTERVAL '30 days'
            GROUP BY DATE(published_at)
            ORDER BY date ASC
        ");
        $timeSeriesData = array_map(fn($r) => [
            'date' => $r->date,
            'count' => (int) $r->count,
        ], $timeSeriesData);

        // WA group distribution
        $waGroupDistribution = Subscriber::whereNotNull('wa_group')
            ->where('wa_group', '!=', '')
            ->select('wa_group', DB::raw('COUNT(*) as count'))
            ->groupBy('wa_group')
            ->orderByDesc('count')
            ->get()
            ->map(fn($item) => ['group' => $item->wa_group, 'count' => (int) $item->count]);

        // Status distribution
        $statusDistribution = Subscriber::whereNotNull('status')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn($item) => ['status' => $item->status, 'count' => (int) $item->count]);

        $data = [
            'summary' => [
                'total_users' => $totalUsers,
                'active_today' => $activeToday,
                'total_clicks' => $totalClicks,
                'top_category' => $topCategory,
                'peak_hour' => $peakHour,
            ],
            'location_analytics' => $locationAnalytics,
            'category_distribution' => $categoryDistribution,
            'top_news' => $topNews,
            'time_series_data' => $timeSeriesData,
            'wa_group_distribution' => $waGroupDistribution->toArray(),
            'status_distribution' => $statusDistribution->toArray(),
            'generated_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(5));
        return response()->json(['success' => true, 'data' => $data, 'cached' => false]);
    }
}
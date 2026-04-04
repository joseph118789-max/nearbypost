<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\Subscriber;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IntelController extends Controller
{
    public function analytics(): JsonResponse
    {
        $cacheKey = "admin_intel_analytics";
        $data = Cache::get($cacheKey);
        if ($data) return response()->json(["success" => true, "data" => $data, "cached" => true]);

        $totalUsers = Subscriber::count();
        $activeToday = Subscriber::whereDate("join_date", Carbon::today())->orWhereDate("created_at", Carbon::today())->count();
        $totalClicks = (int) (NewsItem::sum("click_count") ?? 0);

        $topCategory = NewsItem::whereNotNull("primary_category")->where("primary_category", "!=", "")->select("primary_category", DB::raw("COUNT(*) as count"))->groupBy("primary_category")->orderByDesc("count")->first();

        $peakHourData = NewsItem::whereNotNull("published_at")->select(DB::raw("HOUR(published_at) as hour"), DB::raw("COUNT(*) as count"))->groupBy("hour")->orderByDesc("count")->first();
        $peakHour = $peakHourData ? (int) $peakHourData->hour : 9;

        $locationAnalytics = NewsItem::whereNotNull("main_place_text")->where("main_place_text", "!=", "")->select("main_place_text", DB::raw("COUNT(*) as count"))->groupBy("main_place_text")->orderByDesc("count")->limit(10)->get()->map(fn($item) => ["location" => $item->main_place_text, "count" => (int) $item->count]);

        $categoryDistribution = NewsItem::whereNotNull("primary_category")->where("primary_category", "!=", "")->select("primary_category", DB::raw("COUNT(*) as count"))->groupBy("primary_category")->orderByDesc("count")->get()->map(fn($item) => ["category" => $item->primary_category, "count" => (int) $item->count]);

        $topNews = NewsItem::orderByDesc("click_count")->limit(10)->get()->map(fn($item) => ["id" => $item->id, "title" => $item->title, "clicks" => (int) ($item->click_count ?? 0), "published_at" => $item->published_at, "category" => $item->primary_category]);

        $timeSeriesData = NewsItem::where("published_at", ">=", Carbon::now()->subDays(30))->select(DB::raw("DATE(published_at) as date"), DB::raw("COUNT(*) as count"))->groupBy("date")->orderBy("date")->get()->map(fn($item) => ["date" => $item->date, "count" => (int) $item->count]);

        $waGroupDistribution = Subscriber::whereNotNull("wa_group")->where("wa_group", "!=", "")->select("wa_group", DB::raw("COUNT(*) as count"))->groupBy("wa_group")->orderByDesc("count")->get()->map(fn($item) => ["group" => $item->wa_group, "count" => (int) $item->count]);

        $statusDistribution = Subscriber::whereNotNull("status")->select("status", DB::raw("COUNT(*) as count"))->groupBy("status")->get()->map(fn($item) => ["status" => $item->status, "count" => (int) $item->count]);

        $data = [
            "total_users" => $totalUsers,
            "active_today" => $activeToday,
            "total_clicks" => $totalClicks,
            "top_category" => $topCategory ? ["category" => $topCategory->primary_category, "count" => (int) $topCategory->count] : null,
            "peak_hour" => $peakHour,
            "location_analytics" => $locationAnalytics,
            "category_distribution" => $categoryDistribution,
            "top_news" => $topNews,
            "time_series_data" => $timeSeriesData,
            "wa_group_distribution" => $waGroupDistribution,
            "status_distribution" => $statusDistribution,
            "generated_at" => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(5));
        return response()->json(["success" => true, "data" => $data, "cached" => false]);
    }
}

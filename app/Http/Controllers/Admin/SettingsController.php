<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    const CACHE_KEY_CATEGORIES = "admin_settings_categories";
    const CACHE_KEY_WA_GROUPS = "admin_settings_wa_groups";

    const DEFAULT_CATEGORIES = [
        "primary" => [
            ["id" => 1, "name" => "Crime", "slug" => "crime"],
            ["id" => 2, "name" => "Politics", "slug" => "politics"],
            ["id" => 3, "name" => "Business", "slug" => "business"],
            ["id" => 4, "name" => "Technology", "slug" => "technology"],
            ["id" => 5, "name" => "Entertainment", "slug" => "entertainment"],
            ["id" => 6, "name" => "Sports", "slug" => "sports"],
            ["id" => 7, "name" => "Health", "slug" => "health"],
            ["id" => 8, "name" => "Science", "slug" => "science"],
            ["id" => 9, "name" => "Weather", "slug" => "weather"],
            ["id" => 10, "name" => "Traffic", "slug" => "traffic"],
        ],
        "sub" => [
            "crime" => [["id" => 101, "name" => "Theft", "slug" => "theft"], ["id" => 102, "name" => "Violence", "slug" => "violence"]],
            "politics" => [["id" => 201, "name" => "Local", "slug" => "local"], ["id" => 202, "name" => "National", "slug" => "national"]],
            "business" => [["id" => 301, "name" => "Local Business", "slug" => "local-business"]],
            "technology" => [["id" => 401, "name" => "Gadgets", "slug" => "gadgets"]],
        ],
    ];

    const DEFAULT_WA_GROUPS = [
        ["id" => 1, "name" => "Main Broadcast", "group_id" => "main-broadcast"],
        ["id" => 2, "name" => "Breaking News", "group_id" => "breaking-news"],
        ["id" => 3, "name" => "Local Updates", "group_id" => "local-updates"],
    ];

    public function getCategories(): JsonResponse
    {
        $categories = Cache::get(self::CACHE_KEY_CATEGORIES, self::DEFAULT_CATEGORIES);
        return response()->json(["success" => true, "data" => $categories]);
    }

    public function saveCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            "primary" => "required|array",
            "sub" => "required|array",
        ]);
        Cache::put(self::CACHE_KEY_CATEGORIES, $validated, now()->addDays(365));
        return response()->json(["success" => true, "message" => "Categories saved successfully.", "data" => $validated]);
    }

    public function getWAGroups(): JsonResponse
    {
        $groups = Cache::get(self::CACHE_KEY_WA_GROUPS, self::DEFAULT_WA_GROUPS);
        return response()->json(["success" => true, "data" => $groups]);
    }

    public function saveWAGroups(Request $request): JsonResponse
    {
        $validated = $request->validate([
            "groups" => "required|array",
        ]);
        Cache::put(self::CACHE_KEY_WA_GROUPS, $validated["groups"], now()->addDays(365));
        return response()->json(["success" => true, "message" => "WA groups saved successfully.", "data" => $validated["groups"]]);
    }
}

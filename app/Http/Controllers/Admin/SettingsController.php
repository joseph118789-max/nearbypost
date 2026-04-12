<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    const CACHE_KEY_CATEGORIES = "admin_settings_categories";
    const CACHE_KEY_WA_GROUPS = "admin_settings_wa_groups";

    // Load from subcategories.json - these are the canonical categories
    private function loadSubcategories(): array
    {
        $path = storage_path('app/subcategories.json');
        if (!file_exists($path)) {
            return ['primary_categories' => [], 'sub_categories_map' => []];
        }

        $data = json_decode(file_get_contents($path), true);
        $primaryCategories = [];
        $subCategoriesMap = [];

        foreach ($data as $entry) {
            $primary = $entry['primary_category'];
            $sub = $entry['sub_category'];

            if (!in_array($primary, $primaryCategories)) {
                $primaryCategories[] = $primary;
                $subCategoriesMap[$primary] = [];
            }

            if (!in_array($sub, $subCategoriesMap[$primary])) {
                $subCategoriesMap[$primary][] = $sub;
            }
        }

        sort($primaryCategories);
        foreach ($subCategoriesMap as $k => $v) {
            sort($subCategoriesMap[$k]);
        }

        return [
            'primary_categories' => $primaryCategories,
            'sub_categories_map' => $subCategoriesMap,
        ];
    }

    public function getCategories(): JsonResponse
    {
        $cats = $this->loadSubcategories();
        return response()->json(["success" => true, "data" => $cats]);
    }

    public function saveCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            "primary_categories" => "required|array",
            "sub_categories_map" => "required|array",
        ]);
        Cache::put(self::CACHE_KEY_CATEGORIES, $validated, now()->addDays(365));
        return response()->json(["success" => true, "message" => "Categories saved successfully.", "data" => $validated]);
    }

    const DEFAULT_WA_GROUPS = [
        ["id" => 1, "name" => "Main Broadcast", "group_id" => "main-broadcast"],
        ["id" => 2, "name" => "Breaking News", "group_id" => "breaking-news"],
        ["id" => 3, "name" => "Local Updates", "group_id" => "local-updates"],
    ];

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

<?php

namespace App\Support;

use App\Support\Loc;
use Illuminate\Support\Facades\DB;

/**
 * Category and sub-category names in the reading language.
 *
 * Stories were being translated while the labels around them stayed English, so
 * a Chinese reader got Chinese headlines filed under "Crime & Safety". This
 * resolves a stored category value to whatever the reader should see.
 *
 * Two jobs beyond translation, both of which matter to what the reader sees:
 *
 * It falls back rather than failing. A category with no translation yet shows
 * its English name, which is worse than a translation and far better than
 * blank.
 *
 * It knows which categories are real. Legacy values - 'nation', 'other',
 * 'business' - are still attached to older stories from before the taxonomy was
 * enforced, and were appearing in the sidebar beside the genuine twenty-one.
 * canonical() is what the navigation lists.
 */
class Taxonomy
{
    private static ?array $categories = null;
    private static ?array $subcategories = null;

    /** Canonical lower-cased name => ['en' => ..., 'ms' => ..., 'zh' => ...] */
    private static function categoryNames(): array
    {
        if (self::$categories === null) {
            self::$categories = [];

            foreach (DB::table('categories')->orderBy('id')->get() as $row) {
                self::$categories[mb_strtolower($row->name)] = [
                    'en' => $row->name,
                    'ms' => $row->name_ms ?: $row->name,
                    'zh' => $row->name_zh ?: $row->name,
                ];
            }
        }

        return self::$categories;
    }

    private static function subcategoryNames(): array
    {
        if (self::$subcategories === null) {
            self::$subcategories = [];

            foreach (DB::table('subcategories')->orderBy('id')->get() as $row) {
                self::$subcategories[mb_strtolower($row->sub_category)] = [
                    'en' => $row->sub_category,
                    'ms' => $row->sub_category_ms ?: $row->sub_category,
                    'zh' => $row->sub_category_zh ?: $row->sub_category,
                ];
            }
        }

        return self::$subcategories;
    }

    /** A category name as the reader should see it. */
    public static function category(?string $value, ?string $locale = null): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $locale = $locale ?? Loc::current();
        $names  = self::categoryNames()[mb_strtolower($value)] ?? null;

        return $names[$locale] ?? ucwords($value);
    }

    /** A sub-category name as the reader should see it. */
    public static function subCategory(?string $value, ?string $locale = null): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $locale = $locale ?? Loc::current();
        $names  = self::subcategoryNames()[mb_strtolower($value)] ?? null;

        return $names[$locale] ?? $value;
    }

    /**
     * Is this one of the twenty-one real categories?
     *
     * Older stories carry values from before the taxonomy was enforced -
     * 'nation' covers thousands of rows - and those should not appear as
     * browsable topics.
     */
    public static function isCanonical(?string $value): bool
    {
        return isset(self::categoryNames()[mb_strtolower(trim((string) $value))]);
    }

    /** The canonical category names, lower-cased, in weight order. */
    public static function canonical(): array
    {
        return array_keys(self::categoryNames());
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The 156-row sub-category taxonomy (Category Setup v2.0 s16).
 *
 * The `subcategories` table is authoritative: it carries the primary category,
 * the sub-category name, its weight and whether it is GPS-bearing. This service
 * reads it once per process and answers the two questions the enrichment
 * pipeline needs - what to offer the model, and whether what came back is
 * legitimate for the chosen primary.
 *
 * Spec s10 requires that a sub-category belongs to its primary, and that
 * anything out of range is forced to that primary's "Others".
 */
class SubCategoryTaxonomy
{
    /** @var array<string, list<string>>|null primary category => sub-category names */
    private static ?array $byPrimary = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        if (self::$byPrimary === null) {
            $rows = DB::table('subcategories')
                ->select('primary_category', 'sub_category', 'weight')
                ->orderBy('primary_category')
                ->orderByDesc('weight')
                ->get();

            $map = [];
            foreach ($rows as $row) {
                $map[$row->primary_category][] = $row->sub_category;
            }

            self::$byPrimary = $map;
        }

        return self::$byPrimary;
    }

    /** Sub-category names valid for a primary category, or [] if unknown. */
    public function forPrimary(?string $primary): array
    {
        if ($primary === null) {
            return [];
        }

        foreach ($this->all() as $name => $subs) {
            if (mb_strtolower($name) === mb_strtolower($primary)) {
                return $subs;
            }
        }

        return [];
    }

    /**
     * Validate a model-supplied sub-category against its primary.
     * Returns the canonical sub-category name, that primary's "Others",
     * or null when the primary itself is unknown.
     */
    public function validate(?string $primary, ?string $subCategory): ?string
    {
        $valid = $this->forPrimary($primary);

        if ($valid === []) {
            return null;
        }

        $candidate = trim((string) $subCategory);

        foreach ($valid as $name) {
            if (mb_strtolower($name) === mb_strtolower($candidate)) {
                return $name;
            }
        }

        // Spec s10: out of range forces "Others" for that primary.
        foreach ($valid as $name) {
            if (mb_strtolower($name) === 'others') {
                return $name;
            }
        }

        // Religion has a single sub-category and no "Others" row.
        return $valid[0];
    }

    /**
     * Compact taxonomy block for the prompt: one line per primary category.
     * Sub-categories are listed heaviest first so the model sees the
     * most specific options before the catch-alls.
     */
    public function promptBlock(): string
    {
        $lines = [];

        foreach ($this->all() as $primary => $subs) {
            $lines[] = $primary . ': ' . implode(', ', $subs);
        }

        return implode("\n", $lines);
    }

    /**
     * The sub-category list with ids, for a prompt whose answer is keyed by id.
     *
     * Names alone would have to be matched back on return, and a near-miss
     * would quietly become a different sub-category.
     */
    public function promptBlockWithIds(): string
    {
        $rows = \Illuminate\Support\Facades\DB::table('subcategories')
            ->select('id', 'primary_category', 'sub_category', 'weight')
            ->orderBy('primary_category')
            ->orderByDesc('weight')
            ->get();

        $byPrimary = [];

        foreach ($rows as $row) {
            // S-prefixed: category ids and sub-category ids overlap below 22,
            // and the model conflated them when both were bare numbers.
            $byPrimary[$row->primary_category][] = 'S' . $row->id . ':' . $row->sub_category;
        }

        $lines = [];

        foreach ($byPrimary as $primary => $subs) {
            $lines[] = $primary . ' -> ' . implode(', ', $subs);
        }

        return implode("\n", $lines);
    }
}

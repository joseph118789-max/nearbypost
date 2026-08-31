<?php

namespace App\Support;

/**
 * URL slugs for places and categories.
 *
 * Category names carry an ampersand ("Crime & Safety", "Food & Lifestyle"), so
 * the mapping has to survive a round trip: "-and-" stands in for " & " and
 * nothing else uses it. Without that, /category/crime-safety and
 * /category/crime-and-safety would both half-work and split the same page's
 * ranking between two URLs.
 */
class Slug
{
    public static function make(string $value): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = str_replace('&', ' and ', $slug);
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);

        return trim((string) $slug, '-');
    }

    /** Turn a slug back into the stored name. */
    public static function toName(string $slug): string
    {
        $name = str_replace('-', ' ', mb_strtolower(trim($slug)));
        $name = str_replace(' and ', ' & ', $name);

        return trim($name);
    }
}

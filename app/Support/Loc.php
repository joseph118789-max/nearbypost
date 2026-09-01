<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * The three reading languages, and how they map onto URLs.
 *
 * English is unprefixed and canonical: those URLs are already indexed and there
 * is no reason to churn them. Malay and Chinese sit under /ms and /zh.
 *
 * Each language is its own set of URLs rather than a cookie or a query string,
 * because a search engine has to be able to index the Malay page separately from
 * the English one, and a reader has to be able to share the version they read.
 */
class Loc
{
    public const DEFAULT = 'en';

    /** locale => [native name, html lang, hreflang] */
    public const LOCALES = [
        'en' => ['label' => 'English',  'html' => 'en-MY', 'hreflang' => 'en-MY'],
        'ms' => ['label' => 'Bahasa Melayu', 'html' => 'ms-MY', 'hreflang' => 'ms-MY'],
        'zh' => ['label' => '中文', 'html' => 'zh-Hans-MY', 'hreflang' => 'zh-MY'],
    ];

    public static function all(): array
    {
        return array_keys(self::LOCALES);
    }

    public static function isValid(?string $locale): bool
    {
        return $locale !== null && isset(self::LOCALES[$locale]);
    }

    public static function current(): string
    {
        $locale = app()->getLocale();

        return self::isValid($locale) ? $locale : self::DEFAULT;
    }

    public static function htmlLang(?string $locale = null): string
    {
        return self::LOCALES[$locale ?? self::current()]['html'] ?? 'en-MY';
    }

    public static function label(string $locale): string
    {
        return self::LOCALES[$locale]['label'] ?? $locale;
    }

    /**
     * A flag to stand for a reading language when there is only room for one
     * character.
     *
     * Flags label countries, not languages, and this is the usual imperfect
     * compromise: Chinese is not China, and the readers of the Chinese edition
     * are Malaysian. It is used only as the closed state of the menu - opening
     * it names every language in its own script, which is what a reader
     * actually recognises.
     */
    public static function flag(?string $locale = null): string
    {
        return match ($locale ?? self::current()) {
            'ms' => '🇲🇾',
            'zh' => '🇨🇳',
            default => '🇬🇧',
        };
    }

    /** The route name for a locale: unprefixed for English, prefixed otherwise. */
    public static function routeName(string $name, ?string $locale = null): string
    {
        $locale = $locale ?? self::current();

        if ($locale === self::DEFAULT) {
            return $name;
        }

        $prefixed = $locale . '.' . $name;

        return Route::has($prefixed) ? $prefixed : $name;
    }

    /** A URL in the current (or given) reading language. */
    public static function route(string $name, array $params = [], ?string $locale = null): string
    {
        return route(self::routeName($name, $locale), $params);
    }

    /**
     * The same page in every language, for hreflang and the language switcher.
     * Query parameters are preserved so a reader keeps their radius and period
     * when they switch language.
     */
    public static function alternates(string $routeName, array $params = []): array
    {
        $query = request()->query();
        $out   = [];

        foreach (self::all() as $locale) {
            $url = self::route($routeName, $params, $locale);

            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }

            $out[$locale] = [
                'url'      => $url,
                'label'    => self::label($locale),
                'hreflang' => self::LOCALES[$locale]['hreflang'],
            ];
        }

        return $out;
    }

    /**
     * This same page in every language, derived from the current route.
     *
     * Deriving it here rather than passing it down from each controller means a
     * new page gets its language alternates automatically instead of quietly
     * shipping without them.
     */
    public static function alternatesForCurrent(): array
    {
        $route = request()->route();

        if ($route === null || $route->getName() === null) {
            return [];
        }

        // 'ms.place' and 'place' are the same page in different languages.
        $name = preg_replace(
            '/^(' . implode('|', self::all()) . ')\./',
            '',
            $route->getName()
        );

        $params = $route->parameters();
        unset($params['locale']);

        return self::alternates($name, $params);
    }
}

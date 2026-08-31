<?php

namespace App\Services;

use App\Support\Slug;

/**
 * Structured data for search engines and answer engines.
 *
 * Two audiences, one job. A crawler needs machine-readable facts about what a
 * page lists and where it applies; an answer engine needs the same facts in a
 * form it can lift into a summary without re-reading the markup. Both are served
 * by emitting explicit JSON-LD rather than hoping either one infers structure
 * from the layout.
 *
 * Every story carries contentLocation, because "what is happening near me" is
 * the claim this site makes and the one worth making legible.
 */
class Seo
{
    /** Schema.org graph for a feed page. */
    public function feedGraph(array $stories, string $pageUrl, string $pageName, ?string $place = null, ?string $category = null): array
    {
        $graph = [
            $this->website(),
            $this->breadcrumbs($pageUrl, $pageName, $place, $category),
            $this->itemList($stories, $pageUrl, $pageName),
        ];

        if ($place !== null) {
            $graph[] = $this->place($place);
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => array_values(array_filter($graph)),
        ];
    }

    private function website(): array
    {
        return [
            '@type'       => 'WebSite',
            '@id'         => url('/') . '#website',
            'url'         => url('/'),
            'name'        => config('app.name'),
            'description' => 'Local news and hyperlocal updates for Malaysia.',
            'inLanguage'  => 'en-MY',
            'publisher'   => [
                '@type' => 'Organization',
                '@id'   => url('/') . '#organization',
                'name'  => config('app.name'),
                'url'   => url('/'),
            ],
        ];
    }

    private function itemList(array $stories, string $pageUrl, string $pageName): array
    {
        $elements = [];
        $position = 1;

        foreach ($stories as $story) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'item'     => $this->newsArticle($story),
            ];
        }

        return [
            '@type'           => 'ItemList',
            '@id'             => $pageUrl . '#itemlist',
            'name'            => $pageName,
            'numberOfItems'   => count($elements),
            'itemListElement' => $elements,
        ];
    }

    private function newsArticle(array $story): array
    {
        $article = [
            '@type'            => 'NewsArticle',
            'headline'         => mb_substr((string) ($story['title'] ?? ''), 0, 110),
            'url'              => $story['url'] ?? null,
            'mainEntityOfPage' => $story['url'] ?? null,
            'datePublished'    => isset($story['published_at'])
                ? date('c', strtotime((string) $story['published_at']))
                : null,
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => $story['source'] ?? 'Unknown',
            ],
            'inLanguage'       => 'en-MY',
        ];

        if (!empty($story['summary'])) {
            $article['description'] = mb_substr((string) $story['summary'], 0, 300);
        }

        if (!empty($story['primary_category'])) {
            $article['articleSection'] = ucwords((string) $story['primary_category']);
        }

        if (!empty($story['location_label'])) {
            $location = [
                '@type' => 'Place',
                'name'  => $story['location_label'],
            ];

            if (isset($story['lat'], $story['lng']) && $story['lat'] !== null) {
                $location['geo'] = [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => (float) $story['lat'],
                    'longitude' => (float) $story['lng'],
                ];
            }

            $article['contentLocation'] = $location;
        }

        return array_filter($article, fn ($v) => $v !== null && $v !== '');
    }

    private function place(string $place): array
    {
        return [
            '@type'             => 'Place',
            '@id'               => url('/news/' . Slug::make($place)) . '#place',
            'name'              => $place,
            'address'           => [
                '@type'          => 'PostalAddress',
                'addressLocality' => $place,
                'addressCountry' => 'MY',
            ],
        ];
    }

    private function breadcrumbs(string $pageUrl, string $pageName, ?string $place, ?string $category): array
    {
        $crumbs = [[
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Home',
            'item'     => url('/'),
        ]];

        $position = 2;

        if ($place !== null) {
            $crumbs[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $place,
                'item'     => url('/news/' . Slug::make($place)),
            ];
        }

        if ($category !== null) {
            $crumbs[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => ucwords($category),
                'item'     => $pageUrl,
            ];
        }

        return [
            '@type'           => 'BreadcrumbList',
            '@id'             => $pageUrl . '#breadcrumbs',
            'itemListElement' => $crumbs,
        ];
    }
}

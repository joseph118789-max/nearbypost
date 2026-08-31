<?php

namespace App\Http\Controllers;

use App\Models\LocationAlias;
use App\Services\FeedQuery;
use App\Services\LocationResolver;
use App\Services\Seo;
use App\Support\Slug;
use App\Support\Taxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public pages, rendered on the server.
 *
 * These used to be an empty <div id="app"> filled in by JavaScript: no content
 * for a crawler, no content until scripts ran, and a Nearby tab that only worked
 * for visitors who granted browser location permission.
 *
 * Every place and every category is now its own addressable, indexable URL
 * rather than a JavaScript state change behind a single "/". That is the point
 * of a hyperlocal site: "crime news in Shah Alam" is a page someone can land on,
 * link to and be shown in an answer engine, not a filter setting.
 *
 * Every filter is a plain GET parameter and every control is a link, so the
 * pages work with JavaScript disabled and each view has a shareable URL.
 */
class HomeController extends Controller
{
    /** Time windows offered in the UI, in days. */
    private const WINDOWS = [1 => '24h', 3 => '3d', 7 => '7d', 30 => '30d'];

    /** Radii offered in the UI, in km. */
    private const RADII = [5, 10, 20, 50];

    private const DEFAULT_PLACE  = 'Kuala Lumpur';
    private const DEFAULT_RADIUS = 20;
    private const PLACE_COOKIE   = 'nbp_place';

    public function __construct(
        private FeedQuery $feed,
        private LocationResolver $locations,
        private Seo $seo,
    ) {
    }

    /** Near Me for the reader's remembered or chosen place. */
    public function index(Request $request): View
    {
        return $this->renderPlace($request, $this->rememberedPlace($request), null, true);
    }

    /** /news/{place} - a location's own landing page. */
    public function place(Request $request, string $slug): View
    {
        return $this->renderPlace($request, $this->placeFromSlug($slug), null, false);
    }

    /** /news/{place}/{category} - the long tail, e.g. crime news in Shah Alam. */
    public function placeCategory(Request $request, string $slug, string $categorySlug): View
    {
        return $this->renderPlace(
            $request,
            $this->placeFromSlug($slug),
            $this->categoryFromSlug($categorySlug),
            false
        );
    }

    /** By Interest - category-led, no location involved. */
    public function interest(Request $request): View
    {
        $days     = $this->pick((int) $request->query('days', 7), array_keys(self::WINDOWS), 7);
        $category = $this->cleanCategory($request->query('category'));
        $sub      = $this->cleanSub($request->query('sub'), $category);
        $stories  = $this->feed->latest($category, $days, 24, null, $sub);

        $name = $this->feedName($category, $sub) ?? __('site.latest_news');

        return $this->feedView([
            'tab'         => 'interest',
            'stories'     => $stories,
            'place'       => $this->rememberedPlace($request),
            'radius'      => self::DEFAULT_RADIUS,
            'days'        => $days,
            'category'    => $category,
            'sub'         => $sub,
            'pageTitle'   => $name,
            'heading'     => $name,
            'intro'       => $this->intro($stories, null, $category, $days),
            'description' => $this->description(null, $category, $days, $sub),
            'showRadius'  => false,
        ], null, $category);
    }

    /** /category/{slug} - a category's own page. */
    public function category(Request $request, string $slug): View
    {
        $category = $this->categoryFromSlug($slug);
        $days     = $this->pick((int) $request->query('days', 7), array_keys(self::WINDOWS), 7);
        $sub      = $this->cleanSub($request->query('sub'), $category);
        $stories  = $this->feed->latest($category, $days, 24, null, $sub);

        if ($stories === [] && $sub === null && !$this->categoryExists($category)) {
            throw new NotFoundHttpException('Unknown category');
        }

        $name = $this->feedName($category, $sub) ?? __('site.latest_news');

        return $this->feedView([
            'tab'         => 'interest',
            'stories'     => $stories,
            'place'       => $this->rememberedPlace($request),
            'radius'      => self::DEFAULT_RADIUS,
            'days'        => $days,
            'category'    => $category,
            'sub'         => $sub,
            'pageTitle'   => $name,
            'heading'     => $name,
            'intro'       => $this->intro($stories, null, $category, $days),
            'description' => $this->description(null, $category, $days, $sub),
            'showRadius'  => false,
        ], null, $category);
    }

    public function marketplace(Request $request): View
    {
        return view('pages.marketplace', [
            'tab'        => 'marketplace',
            'place'      => $this->rememberedPlace($request),
            'categories' => $this->feed->categories(),
            'places'     => $this->popularPlaces(),
            'placeList'  => $this->placesWithNews(),
            'pageTitle'  => 'Marketplace',
        ]);
    }

    public function legal(Request $request, string $page): View
    {
        $pages = [
            'terms'      => 'Terms of Use',
            'privacy'    => 'Privacy Policy',
            'disclaimer' => 'Disclaimer',
        ];

        if (!isset($pages[$page])) {
            throw new NotFoundHttpException('Unknown page');
        }

        return view('pages.legal', [
            'tab'        => null,
            'page'       => $page,
            'pageTitle'  => $pages[$page],
            'categories' => $this->feed->categories(),
            'places'     => $this->popularPlaces(),
            'placeList'  => $this->placesWithNews(),
            'place'      => $this->rememberedPlace($request),
        ]);
    }

    // ── shared rendering ────────────────────────────────────────────────

    private function renderPlace(Request $request, string $place, ?string $category, bool $remember): View
    {
        $radius = $this->pick((int) $request->query('radius', self::DEFAULT_RADIUS), self::RADII, self::DEFAULT_RADIUS);
        $days   = $this->pick((int) $request->query('days', 7), array_keys(self::WINDOWS), 7);
        $category ??= $this->cleanCategory($request->query('category'));
        $sub       = $this->cleanSub($request->query('sub'), $category);

        $coords  = $this->locations->resolve($place);
        $stories = [];

        if ($coords) {
            $stories = $this->feed->nearby(
                $coords['lat'], $coords['lng'], (float) $radius, $days, 24, $category, null, $sub
            );
        }

        if ($remember && $request->query('place')) {
            Cookie::queue(self::PLACE_COOKIE, $place, 60 * 24 * 90);
        }

        // The narrowest thing chosen is what the page is about: Scams & Fraud
        // in Kuala Lumpur, not Crime & Safety in Kuala Lumpur.
        $topic = $sub !== null
            ? Taxonomy::subCategory($sub)
            : ($category !== null ? Taxonomy::category($category) : null);

        $name = $topic !== null
            ? __('site.category_in', ['category' => $topic, 'place' => $place])
            : __('site.news_near', ['place' => $place]);

        return $this->feedView([
            'tab'         => 'nearme',
            'stories'     => $stories,
            'place'       => $place,
            'radius'      => $radius,
            'days'        => $days,
            'category'    => $category,
            'sub'         => $sub,
            'unresolved'  => $coords === null,
            'pageTitle'   => $name,
            'heading'     => $name,
            'intro'       => $this->intro($stories, $place, $category, $days),
            'description' => $this->description($place, $category, $days, $sub),
            'showRadius'  => true,
            'coords'      => $coords,
        ], $place, $category);
    }

    private function feedView(array $data, ?string $place, ?string $category): View
    {
        $data['categories'] = $this->feed->categories();

        // Places ordered by what is nearest to this reader when the page knows
        // where they are, and by how much news each carries when it does not.
        $data['placeList']  = $this->placesWithNews($data['coords'] ?? null);
        $data['places']     = array_column($data['placeList'], 'name');
        $data['placesNear'] = !empty($data['coords']);

        // Sub-topics of the chosen topic, so the label on every card becomes
        // somewhere a reader can go.
        // Counted over the same window as the feed below: a chip reading "3"
        // must return three stories.
        $data['subCategories'] = $category
            ? $this->feed->subCategories($category, $data['days'] ?? FeedQuery::DEFAULT_WINDOW_DAYS)
            : [];

        $data['windows']    = self::WINDOWS;
        $data['radii']      = self::RADII;
        $data['canonical']  = url()->current();
        $data['jsonLd']     = $this->seo->feedGraph(
            $data['stories'],
            $data['canonical'],
            $data['pageTitle'],
            $place,
            $category
        );

        return view('pages.feed', $data);
    }

    /**
     * A short factual lead-in.
     *
     * Answer engines quote a page's opening sentences. Stating plainly what this
     * page covers - how many stories, where, over what period - gives them
     * something accurate to lift instead of assembling a claim from card titles.
     */
    private function intro(array $stories, ?string $place, ?string $category, int $days): string
    {
        $count  = count($stories);

        $window = $days === 1
            ? __('site.window_24h')
            : __('site.window_days', ['days' => $days]);

        $what = $category
            ? __('site.category_stories', ['category' => Taxonomy::category($category)])
            : __('site.stories');

        if ($count === 0) {
            return $place
                ? __('site.intro_none_place', ['what' => $what, 'place' => $place, 'window' => $window])
                : __('site.intro_none', ['what' => $what, 'window' => $window]);
        }

        return $place
            ? __('site.intro_place', ['count' => $count, 'place' => $place, 'window' => $window])
            : __('site.intro_all', ['count' => $count, 'window' => $window]);
    }

    private function description(?string $place, ?string $category, int $days, ?string $sub = null): string
    {
        // Describe what the page holds, not the topic it sits under.
        $topic = $sub !== null
            ? Taxonomy::subCategory($sub)
            : ($category !== null ? Taxonomy::category($category) : null);

        $what = $topic !== null ? $topic . ' news' : 'Local news';

        return $place
            ? "{$what} near {$place}, Malaysia. Updated continuously from Malaysian news sources."
            : "{$what} from across Malaysia. Updated continuously from Malaysian news sources.";
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Places worth linking from every page, for crawl depth and for readers. */
    private function popularPlaces(): array
    {
        return array_column($this->placesWithNews(), 'name');
    }

    /**
     * Places that actually have news, with how much.
     *
     * This was an alphabetical slice of the gazetteer: every city and state on
     * file, sorted by spelling, whether or not a single story mentioned it. So
     * the panel opened with Alor Gajah and Alor Setar while Kuala Lumpur's 299
     * stories and Putrajaya's 88 were nowhere in the list. There was no reason
     * behind the order because there was no reason in it at all, and clicking
     * through often landed on an empty page.
     *
     * The list is derived from the feed now. Given the reader's coordinates it
     * is ordered by distance, which is the one ordering this site can claim to
     * be about; without them it falls back to volume. Either way the count is
     * shown, so the order visibly means something.
     *
     * @param  array{lat: float, lng: float}|null  $coords
     * @return list<array{name: string, count: int, lat: float|null, lng: float|null, km: float|null}>
     */
    private function placesWithNews(?array $coords = null): array
    {
        $places = cache()->remember('feed:places:v2', 900, function () {
            $rows = DB::table('feed_ready_items')
                ->where('is_active', true)
                ->where('published_at', '>=', now()->subDays(14))
                ->whereNotNull('location_label')
                ->where('location_label', '<>', '')
                ->selectRaw('location_label AS name, count(*) AS n, avg(lat) AS lat, avg(lng) AS lng')
                ->groupBy('location_label')
                ->orderByRaw('count(*) DESC')
                ->get();

            $known = [];

            foreach ($rows as $row) {
                $known[mb_strtolower($row->name)] = true;
            }

            // "George Town, Penang" and "George Town" are one place. Left split
            // they read as two thin entries and neither looks worth a click.
            $merged = [];

            foreach ($rows as $row) {
                $name = $row->name;
                $head = trim(explode(',', $name)[0]);

                if ($head !== '' && $head !== $name && isset($known[mb_strtolower($head)])) {
                    $name = $head;
                }

                $key = mb_strtolower($name);

                if (!isset($merged[$key])) {
                    // Rows arrive busiest first, so the coordinates come from
                    // the spelling that carries the most stories.
                    $merged[$key] = [
                        'name'  => $name,
                        'count' => 0,
                        'lat'   => $row->lat === null ? null : (float) $row->lat,
                        'lng'   => $row->lng === null ? null : (float) $row->lng,
                        'km'    => null,
                    ];
                }

                $merged[$key]['count'] += (int) $row->n;
            }

            // One story is a thin page to send a reader to.
            $merged = array_values(array_filter($merged, fn ($p) => $p['count'] >= 2));

            usort($merged, fn ($a, $b) => $b['count'] <=> $a['count']);

            return array_slice($merged, 0, 60);
        });

        if ($coords === null) {
            return array_slice($places, 0, 40);
        }

        $near = [];

        foreach ($places as $place) {
            if ($place['lat'] === null) {
                continue;
            }

            $place['km'] = $this->km($coords['lat'], $coords['lng'], $place['lat'], $place['lng']);
            $near[] = $place;
        }

        usort($near, fn ($a, $b) => $a['km'] <=> $b['km']);

        return array_slice($near, 0, 40);
    }

    /** Great-circle distance in kilometres. */
    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $angle = cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1))
               + sin(deg2rad($lat1)) * sin(deg2rad($lat2));

        return round(6371.0 * acos(min(1.0, max(-1.0, $angle))), 1);
    }

    /** The heading for a feed narrowed by topic, and by sub-topic under it. */
    private function feedName(?string $category, ?string $sub): ?string
    {
        if ($sub !== null) {
            return __('site.category_news', ['category' => Taxonomy::subCategory($sub)]);
        }

        if ($category !== null) {
            return __('site.category_news', ['category' => Taxonomy::category($category)]);
        }

        return null;
    }

    private function categoryExists(string $category): bool
    {
        foreach ($this->feed->categories() as $known) {
            if (mb_strtolower($known) === mb_strtolower($category)) {
                return true;
            }
        }

        return false;
    }

    private function rememberedPlace(Request $request): string
    {
        $place = trim((string) $request->query('place', ''));

        if ($place !== '') {
            return mb_substr($place, 0, 120);
        }

        $cookie = trim((string) $request->cookie(self::PLACE_COOKIE, ''));

        return $cookie !== '' ? mb_substr($cookie, 0, 120) : self::DEFAULT_PLACE;
    }

    private function placeFromSlug(string $slug): string
    {
        $name = Slug::toName($slug);

        // Prefer the registry's canonical spelling so the page title, the
        // heading and the schema all agree with what the pipeline stored.
        $match = LocationAlias::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(canonical_name) = ?', [$name])
            ->first();

        return $match->canonical_name ?? ucwords($name);
    }

    private function categoryFromSlug(string $slug): string
    {
        return Slug::toName($slug);
    }

    private function cleanCategory(?string $category): ?string
    {
        $category = trim((string) $category);

        if ($category === '' || mb_strtolower($category) === 'all') {
            return null;
        }

        return mb_substr($category, 0, 60);
    }

    /**
     * A sub-topic only narrows a topic, so it means nothing on its own and is
     * accepted only when the topic it belongs to is really carrying it. An
     * unknown value is dropped rather than served as an empty feed.
     */
    private function cleanSub(?string $sub, ?string $category): ?string
    {
        $sub = trim((string) $sub);

        if ($sub === '' || $category === null || mb_strtolower($sub) === 'all') {
            return null;
        }

        foreach ($this->feed->subCategories($category) as $known) {
            if (mb_strtolower($known['name']) === mb_strtolower($sub)) {
                return $known['name'];
            }
        }

        return null;
    }

    private function pick(int $value, array $allowed, int $fallback): int
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}

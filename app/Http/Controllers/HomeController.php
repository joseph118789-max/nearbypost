<?php

namespace App\Http\Controllers;

use App\Models\LocationAlias;
use App\Services\FeedQuery;
use App\Services\LocationResolver;
use App\Services\Seo;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
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
        $stories  = $this->feed->latest($category, $days, 24);

        $name = $category
            ? __('site.category_news', ['category' => ucwords($category)])
            : __('site.latest_news');

        return $this->feedView([
            'tab'         => 'interest',
            'stories'     => $stories,
            'place'       => $this->rememberedPlace($request),
            'radius'      => self::DEFAULT_RADIUS,
            'days'        => $days,
            'category'    => $category,
            'pageTitle'   => $name,
            'heading'     => $name,
            'intro'       => $this->intro($stories, null, $category, $days),
            'description' => $this->description(null, $category, $days),
            'showRadius'  => false,
        ], null, $category);
    }

    /** /category/{slug} - a category's own page. */
    public function category(Request $request, string $slug): View
    {
        $category = $this->categoryFromSlug($slug);
        $days     = $this->pick((int) $request->query('days', 7), array_keys(self::WINDOWS), 7);
        $stories  = $this->feed->latest($category, $days, 24);

        if ($stories === [] && !$this->categoryExists($category)) {
            throw new NotFoundHttpException('Unknown category');
        }

        $name = __('site.category_news', ['category' => ucwords($category)]);

        return $this->feedView([
            'tab'         => 'interest',
            'stories'     => $stories,
            'place'       => $this->rememberedPlace($request),
            'radius'      => self::DEFAULT_RADIUS,
            'days'        => $days,
            'category'    => $category,
            'pageTitle'   => $name,
            'heading'     => $name,
            'intro'       => $this->intro($stories, null, $category, $days),
            'description' => $this->description(null, $category, $days),
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
            'place'      => $this->rememberedPlace($request),
        ]);
    }

    // ── shared rendering ────────────────────────────────────────────────

    private function renderPlace(Request $request, string $place, ?string $category, bool $remember): View
    {
        $radius = $this->pick((int) $request->query('radius', self::DEFAULT_RADIUS), self::RADII, self::DEFAULT_RADIUS);
        $days   = $this->pick((int) $request->query('days', 7), array_keys(self::WINDOWS), 7);
        $category ??= $this->cleanCategory($request->query('category'));

        $coords  = $this->locations->resolve($place);
        $stories = [];

        if ($coords) {
            $stories = $this->feed->nearby($coords['lat'], $coords['lng'], (float) $radius, $days, 24, $category);
        }

        if ($remember && $request->query('place')) {
            Cookie::queue(self::PLACE_COOKIE, $place, 60 * 24 * 90);
        }

        $name = $category
            ? __('site.category_in', ['category' => ucwords($category), 'place' => $place])
            : __('site.news_near', ['place' => $place]);

        return $this->feedView([
            'tab'         => 'nearme',
            'stories'     => $stories,
            'place'       => $place,
            'radius'      => $radius,
            'days'        => $days,
            'category'    => $category,
            'unresolved'  => $coords === null,
            'pageTitle'   => $name,
            'heading'     => $name,
            'intro'       => $this->intro($stories, $place, $category, $days),
            'description' => $this->description($place, $category, $days),
            'showRadius'  => true,
            'coords'      => $coords,
        ], $place, $category);
    }

    private function feedView(array $data, ?string $place, ?string $category): View
    {
        $data['categories'] = $this->feed->categories();
        $data['places']     = $this->popularPlaces();
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
            ? __('site.category_stories', ['category' => mb_strtolower($category)])
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

    private function description(?string $place, ?string $category, int $days): string
    {
        $what = $category ? ucwords($category) . ' news' : 'Local news';

        return $place
            ? "{$what} near {$place}, Malaysia. Updated continuously from Malaysian news sources."
            : "{$what} from across Malaysia. Updated continuously from Malaysian news sources.";
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Places worth linking from every page, for crawl depth and for readers. */
    private function popularPlaces(): array
    {
        return cache()->remember('seo:places', 3600, function () {
            return LocationAlias::query()
                ->where('is_active', true)
                ->whereIn('alias_type', ['city', 'state'])
                ->orderBy('canonical_name')
                ->pluck('canonical_name')
                ->unique()
                ->values()
                ->all();
        });
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

    private function pick(int $value, array $allowed, int $fallback): int
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}

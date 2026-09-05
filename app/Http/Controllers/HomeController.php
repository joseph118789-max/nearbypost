<?php

namespace App\Http\Controllers;

use App\Models\LocationAlias;
use App\Services\FeedQuery;
use App\Services\GeocodingService;
use App\Services\GeoIp;
use App\Services\LocationResolver;
use App\Services\Seo;
use App\Support\Loc;
use App\Support\Slug;
use App\Support\Taxonomy;
use Illuminate\Http\RedirectResponse;
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
    private const DEFAULT_RADIUS = 10;   // owner, 4 Sep: 10 km and Latest by default
    private const PLACE_COOKIE   = 'nbp_place';

    public function __construct(
        private FeedQuery $feed,
        private LocationResolver $locations,
        private Seo $seo,
        private GeocodingService $geocoder,
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
        $source   = $this->cleanSource($request->query('source'));
        // Which country's news. Malaysia unless the reader picks another; the
        // list offers only countries that have live stories to show.
        $countries = $this->liveCountries();
        $country   = strtoupper((string) $request->query('country', ''));

        if ($country === '') {
            // No choice made: the reader's own country, when this site has
            // news from it; Malaysia otherwise. Cloudflare's header says
            // where they are on every request, so this costs nothing.
            $iso2 = app(GeoIp::class)->country($request->ip(), $request->userAgent(), $request->headers->get('CF-IPCountry'));
            $iso3 = $iso2 ? \App\Services\Geo\Boundaries\Iso3166::iso3($iso2) : null;
            $country = $iso3 && isset($countries[$iso3]) ? $iso3 : 'MYS';
        }

        $country = isset($countries[$country]) ? $country : 'MYS';
        $stories   = $this->feed->latest($category, $days, 24, null, $sub, $source, $country);

        $name = $this->feedName($category, $sub)
            ?? ($country === 'MYS' ? __('site.latest_news') : __('site.latest_news_in', ['country' => $countries[$country]]));

        return $this->feedView([
            'tab'         => 'interest',
            'stories'     => $stories,
            'place'       => $this->rememberedPlace($request),
            'radius'      => self::DEFAULT_RADIUS,
            'days'        => $days,
            'category'    => $category,
            'sub'         => $sub,
            'source'      => $source,
            'country'     => $country,
            'countries'   => $countries,
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
        $source   = $this->cleanSource($request->query('source'));
        $stories  = $this->feed->latest($category, $days, 24, null, $sub, $source);

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
            'source'      => $source,
            'pageTitle'   => $name,
            'heading'     => $name,
            'intro'       => $this->intro($stories, null, $category, $days),
            'description' => $this->description(null, $category, $days, $sub),
            'showRadius'  => false,
        ], null, $category);
    }

    /** The invitation form. Spec: the owner's ask of 5 Sep 2026. */
    public function share(Request $request): View
    {
        return view('pages.share', [
            'tab'       => '',
            'place'     => $this->rememberedPlace($request),
            'pageTitle' => 'Share your news with Nearbypost',
        ]);
    }

    /**
     * A publisher offering their site.
     *
     * ⛔ Nothing is switched on here. The row lands in a queue, a command
     * probes the site, and a person decides - see SourceRequests.
     */
    public function shareSubmit(Request $request): \Illuminate\Http\RedirectResponse
    {
        $bucket = hash('sha256', (string) $request->ip() . '|' . now()->toDateString() . '|' . config('app.key'));

        $result = \App\Services\Ingest\SourceRequests::submit([
            'website_url'     => $request->input('website_url'),
            'site_name'       => $request->input('site_name'),
            'contact_name'    => $request->input('contact_name'),
            'contact_email'   => $request->input('contact_email'),
            'describes'       => $request->input('describes'),
            'social_links'    => $request->input('social_links', []),
            'speaks_for_site' => $request->boolean('speaks_for_site'),

            // What they agreed we may show, recorded with the wording and the
            // moment. Without a scope there is nothing to withdraw later.
            'allow_excerpt'    => $request->boolean('allow_excerpt'),
            'allow_ai_summary' => $request->boolean('allow_ai_summary'),
            'allow_image'      => $request->boolean('allow_image'),
        ], $bucket);

        if (!($result['ok'] ?? false)) {
            return back()->withInput()->withErrors(['share' => $result['why'] ?? 'That did not go through.']);
        }

        return back()->with('shared', true);
    }

    public function marketplace(Request $request): View
    {
        return view('pages.marketplace', [
            'tab'        => 'marketplace',
            'place'      => $this->rememberedPlace($request),
            'categories' => $this->feed->categories(),
            'sections'   => \App\Services\Marketplace\MarketplaceBrowse::sections(
                \App\Services\Geo\SourceCountry::SITE
            ),
            'pageTitle'  => 'Marketplace',
        ]);
    }

    /**
     * One section of the Marketplace.
     *
     * While the section is closed this shows made-up listings so a reader - and
     * the person building it - can see what it will look like. Every one of
     * them is labelled; see SampleListings for why they are not in the database.
     */
    public function marketplaceSection(Request $request, string $section): View
    {
        $country = \App\Services\Geo\SourceCountry::SITE;
        $found   = \App\Services\Marketplace\MarketplaceBrowse::section($section, $country);

        if ($found === null) {
            throw new NotFoundHttpException('Unknown section');
        }

        return view('pages.marketplace-section', [
            'tab'       => 'marketplace',
            'place'     => $this->rememberedPlace($request),
            'section'   => $found,
            'label'     => \App\Services\Marketplace\SampleListings::LABEL,
            'pageTitle' => $found['name'] . ' — Marketplace',
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
            'place'      => $this->rememberedPlace($request),
        ]);
    }

    /**
     * Turn the browser's coordinates into an ordinary place URL.
     *
     * The reader allows their location; the browser gives a point. Everything
     * downstream - the heading, the remembered place, the link they might send
     * someone - is built around a NAME, so the point becomes a name here and
     * the request continues as though they had typed it.
     *
     * A redirect rather than a render, so the address bar ends up somewhere
     * shareable and a refresh does not ask the browser for a position again.
     */
    public function locate(Request $request): RedirectResponse
    {
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');

        $sane = $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
            && ($lat !== 0.0 || $lng !== 0.0);

        $place = $sane ? $this->geocoder->reverse($lat, $lng) : null;

        if ($place === null) {
            // Nothing was found for the point, so say so rather than dropping
            // the reader on a feed for somewhere else without explanation.
            return redirect()->to(Loc::route('home') . '?located=no');
        }

        $query = ['place' => $place];

        foreach (['radius', 'days', 'category', 'sub', 'source'] as $keep) {
            if ($request->filled($keep)) {
                $query[$keep] = $request->query($keep);
            }
        }

        return redirect()->to(Loc::route('home') . '?' . http_build_query($query));
    }

    // ── shared rendering ────────────────────────────────────────────────

    private function renderPlace(Request $request, string $place, ?string $category, bool $remember): View
    {
        $radius = $this->pick((int) $request->query('radius', self::DEFAULT_RADIUS), self::RADII, self::DEFAULT_RADIUS);
        $days   = $this->pick((int) $request->query('days', 7), array_keys(self::WINDOWS), 7);
        $category ??= $this->cleanCategory($request->query('category'));
        $sub       = $this->cleanSub($request->query('sub'), $category);
        $source    = $this->cleanSource($request->query('source'));
        $sort      = $request->query('sort') === 'distance' ? 'distance' : 'time';   // Latest unless the reader picks Nearest

        $coords  = $this->locations->resolve($place);
        $stories = [];

        if ($coords) {
            $stories = $this->feed->nearby(
                $coords['lat'], $coords['lng'], (float) $radius, $days, 24, $category, null, $sub, $source, $sort
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
            'source'      => $source,
            'sort'        => $sort,
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

        // The topic browser in the right column: the main categories, and one
        // level down, the sub-categories of whichever is open. Both modes get
        // it, but the links differ because the modes do - on Near Me a topic
        // keeps you at your place, on By Interest it goes to that topic's own
        // indexable page.
        $nearMe = ($data['tab'] ?? '') === 'nearme';

        $data['topicsRootUrl'] = $nearMe
            ? request()->fullUrlWithQuery(['category' => null, 'sub' => null])
            : Loc::route('interest');

        $data['topicUrls'] = [];

        foreach ($data['categories'] as $known) {
            $data['topicUrls'][$known] = $nearMe
                ? request()->fullUrlWithQuery(['category' => $known, 'sub' => null])
                : Loc::route('category', ['slug' => Slug::make($known)]);
        }

        // Counted over the same window as the feed beside them - and, on Near
        // Me, within the same radius - so a chip reading "3" returns three
        // stories rather than three somewhere in the country.
        $data['subCategories'] = $category
            ? $this->feed->subCategories(
                $category,
                $data['days'] ?? FeedQuery::DEFAULT_WINDOW_DAYS,
                $nearMe ? ($data['coords'] ?? null) : null,
                (float) ($data['radius'] ?? self::DEFAULT_RADIUS)
            )
            : [];

        // The whole tree, so opening a topic in the right column is instant
        // rather than a page load. Same window and radius as the feed beside
        // it, so a count of 3 returns three stories.
        $data['topicTree'] = $this->feed->allSubCategories(
            $data['days'] ?? FeedQuery::DEFAULT_WINDOW_DAYS,
            $nearMe ? ($data['coords'] ?? null) : null,
            (float) ($data['radius'] ?? self::DEFAULT_RADIUS)
        );

        // A link per sub-topic, built here because the two modes address a
        // topic differently and a Blade template is the wrong place to know
        // that. Near Me keeps the reader at their place; By Interest goes to
        // the topic's own indexable page.
        $data['subUrls'] = [];

        foreach ($data['topicTree'] as $catKey => $subs) {
            foreach ($subs as $s) {
                $data['subUrls'][$catKey][$s['name']] = $nearMe
                    ? request()->fullUrlWithQuery(['category' => $catKey, 'sub' => $s['name']])
                    : Loc::route('category', ['slug' => Slug::make($catKey)]) . '?sub=' . rawurlencode($s['name']);
            }
        }

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

        if ($cookie !== '') {
            return mb_substr($cookie, 0, 120);
        }

        // Nothing chosen and nothing remembered, so this is a first visit.
        // Opening every feed in Kuala Lumpur regardless of who is reading it is
        // the wrong first impression for a site whose whole premise is
        // distance, so the reader is placed by their IP and the answer is
        // remembered like any other choice - one keystroke from being
        // corrected, and never consulted again once it has been.
        $located = app(GeoIp::class)->place(
            $request->ip(),
            $request->userAgent(),
            // Cloudflare has already worked out the country; there is no reason
            // to pay an external lookup to disagree with it.
            $request->headers->get('CF-IPCountry')
        );

        if ($located !== null) {
            Cookie::queue(self::PLACE_COOKIE, $located, 60 * 24 * 90);

            return mb_substr($located, 0, 120);
        }

        return self::DEFAULT_PLACE;
    }

    /**
     * Who wrote the stories: everyone, us, or other readers.
     */
    /**
     * Countries with live stories in the last 30 days, Malaysia first and the
     * rest by how much there is to read. Names from the ISO table, not the
     * boundary files, whose level-0 names are not all tidy.
     *
     * @return array<string, string> iso3 => name
     */
    private function liveCountries(): array
    {
        $rows = \Illuminate\Support\Facades\Cache::remember('live-countries', 600, function () {
            return \Illuminate\Support\Facades\DB::table('feed_ready_items')
                ->where('is_active', true)->where('published_at', '>=', now()->subDays(30))
                ->whereNotNull('geo_country_code')
                ->selectRaw('geo_country_code as c, count(*) as n')->groupBy('c')->orderByDesc('n')->limit(40)->get()
                ->map(fn ($r) => ['c' => $r->c, 'n' => (int) $r->n])->all();
        });

        $out = ['MYS' => \App\Services\Geo\Boundaries\Iso3166::name('MYS') ?? 'Malaysia'];

        foreach ($rows as $r) {
            if ($r['c'] === 'MYS' || $r['n'] < 2) {
                continue;
            }

            $name = \App\Services\Geo\Boundaries\Iso3166::name($r['c']);

            if ($name !== null) {
                $out[$r['c']] = $name;
            }
        }

        return $out;
    }

    private function cleanSource(?string $source): ?string
    {
        $source = mb_strtolower(trim((string) $source));

        return in_array($source, ['official', 'unofficial'], true) ? $source : null;
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

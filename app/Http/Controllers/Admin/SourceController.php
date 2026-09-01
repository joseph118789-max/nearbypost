<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SourceBlocklist;
use App\Services\SourceProbe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The handbook: where the news comes from, and what we know about getting it.
 *
 * Three levels, because the work divides that way. A country is owned by
 * whoever knows its press - the person who knows Harian Metro mislabels its
 * timezone is not the person who will know the equivalent about a British
 * paper. Inside a country, a publisher is one entry. Inside a publisher are the
 * section feeds that each fill a different part of the site.
 *
 * An admin given a country sees only that country and is taken straight into
 * it, so a British editor never scrolls past thirty Malaysian publishers.
 */
class SourceController extends Controller
{
    public const INTERVALS = [
        15   => 'Every 15 minutes',
        30   => 'Every 30 minutes',
        60   => 'Hourly',
        180  => 'Every 3 hours',
        360  => 'Every 6 hours',
        720  => 'Twice a day',
        1440 => 'Once a day',
    ];

    /** Offered when adding a country; any ISO code can still be typed in. */
    public const COUNTRIES = [
        'MY' => 'Malaysia',    'SG' => 'Singapore',  'ID' => 'Indonesia',
        'TH' => 'Thailand',    'VN' => 'Vietnam',    'PH' => 'Philippines',
        'BN' => 'Brunei',      'HK' => 'Hong Kong',  'CN' => 'China',
        'TW' => 'Taiwan',      'JP' => 'Japan',      'KR' => 'South Korea',
        'IN' => 'India',       'AU' => 'Australia',  'NZ' => 'New Zealand',
        'GB' => 'United Kingdom', 'US' => 'United States', 'AE' => 'UAE',
        'QA' => 'Qatar',       'SA' => 'Saudi Arabia',
    ];

    public function __construct(
        private SourceProbe $probe,
        private SourceBlocklist $blocklist,
    ) {
    }

    /** Level one: the countries, or straight past them for a country admin. */
    public function countries(): View|RedirectResponse
    {
        $mine = Auth::guard('admin')->user()->country ?? null;

        if ($mine) {
            return redirect()->route('admin.sources.index', ['country' => $mine]);
        }

        $rows = DB::table('sources')
            ->selectRaw("country,
                         count(*) FILTER (WHERE parent_source_id IS NULL) AS publishers,
                         count(*) FILTER (WHERE parent_source_id IS NOT NULL) AS sections,
                         count(*) FILTER (WHERE is_active) AS active,
                         coalesce(sum(items_contributed), 0) AS items,
                         count(*) FILTER (WHERE expect_note IS NOT NULL AND expect_note <> '') AS documented,
                         count(*) AS total")
            ->groupBy('country')
            ->orderByDesc('items')
            ->get();

        return view('admin.sources.countries', [
            'rows'      => $rows,
            'names'     => self::COUNTRIES,
            'blocked'   => DB::table('blocked_urls')->count(),
        ]);
    }

    /** Add a country so someone can start building it out. */
    public function addCountry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2', 'alpha'],
        ]);

        return redirect()->route('admin.sources.index', ['country' => mb_strtoupper($data['country'])])
            ->with('status', 'Add the first publisher below.');
    }

    /** Level two: the publishers of one country. */
    public function index(Request $request): View
    {
        $country = $this->countryFor($request->query('country'));

        $roots = DB::table('sources')
            ->where('country', $country)
            ->whereNull('parent_source_id')
            ->orderByDesc('is_active')
            ->orderByDesc('items_contributed')
            ->orderBy('name')
            ->get();

        $children = DB::table('sources')
            ->where('country', $country)
            ->whereNotNull('parent_source_id')
            ->selectRaw('parent_source_id, count(*) AS sections,
                         count(*) FILTER (WHERE is_active) AS active_sections,
                         coalesce(sum(items_contributed), 0) AS items')
            ->groupBy('parent_source_id')
            ->get()
            ->keyBy('parent_source_id');

        return view('admin.sources.index', [
            'country'   => $country,
            'name'      => self::COUNTRIES[$country] ?? $country,
            'roots'     => $roots,
            'children'  => $children,
            'canLeave'  => (Auth::guard('admin')->user()->country ?? null) === null,
        ]);
    }

    /** Add a publisher to a country. */
    public function addPublisher(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'name'    => ['required', 'string', 'min:2', 'max:120'],
            'url'     => ['required', 'url', 'max:900'],
            'kind'    => ['required', 'in:rss,index'],
        ]);

        $country = $this->countryFor($data['country']);

        if ($this->blocklist->isBlocked($data['url'])) {
            return back()->withErrors(['url' => 'That address is on the do-not-visit list. Remove it from there first.']);
        }

        $id = DB::table('sources')->insertGetId([
            'name'          => $data['name'],
            'country'       => $country,
            'base_url'      => $this->originOf($data['url']),
            'rss_url'       => $data['kind'] === 'rss' ? $data['url'] : null,
            'index_url'     => $data['kind'] === 'index' ? $data['url'] : null,
            'source_kind'   => $data['kind'],
            'source_type'   => 'manual',
            'language'      => 'Unknown',
            'is_active'     => false,
            'priority_tier' => 'secondary',
            'direct_rss_supported'  => $data['kind'] === 'rss',
            'google_news_supported' => false,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return redirect()->route('admin.sources.show', ['id' => $id])
            ->with('status', 'Added, switched off. Test it, write the notes, then switch it on.');
    }

    /** Level three: one publisher, and each of its section feeds. */
    public function show(int $id): View
    {
        $source = DB::table('sources')->where('id', $id)->first();

        if (!$source) {
            throw new NotFoundHttpException('No such source');
        }

        $root = $source->parent_source_id
            ? DB::table('sources')->where('id', $source->parent_source_id)->first()
            : $source;

        $sections = DB::table('sources')
            ->where('parent_source_id', $root->id)
            ->orderBy('section')
            ->orderBy('name')
            ->get();

        return view('admin.sources.show', [
            'root'      => $root,
            'sections'  => $sections,
            'intervals' => self::INTERVALS,
            'recent'    => $this->recentFrom($root, $sections),
            'probe'     => session('probe'),
        ]);
    }

    /** Add a section feed by hand. */
    public function addSection(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'section' => ['required', 'string', 'min:2', 'max:40'],
            'url'     => ['required', 'url', 'max:900'],
            'kind'    => ['required', 'in:rss,index'],
        ]);

        $root = DB::table('sources')->where('id', $id)->first();

        if (!$root) {
            throw new NotFoundHttpException('No such source');
        }

        if ($this->blocklist->isBlocked($data['url'])) {
            return back()->withErrors(['url' => 'That address is on the do-not-visit list. Remove it from there first.']);
        }

        if (DB::table('sources')->where('rss_url', $data['url'])->orWhere('index_url', $data['url'])->exists()) {
            return back()->withErrors(['url' => 'That address is already being read.']);
        }

        DB::table('sources')->insert([
            'name'            => $root->name . ' - ' . ucfirst($data['section']),
            'country'         => $root->country,
            'parent_source_id' => $root->id,
            'section'         => mb_strtolower($data['section']),
            'base_url'        => $root->base_url,
            'rss_url'         => $data['kind'] === 'rss' ? $data['url'] : null,
            'index_url'       => $data['kind'] === 'index' ? $data['url'] : null,
            'source_kind'     => $data['kind'],
            'source_type'     => 'section',
            'language'        => $root->language,
            'is_active'       => false,
            'priority_tier'   => 'secondary',
            'direct_rss_supported'  => $data['kind'] === 'rss',
            'google_news_supported' => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return back()->with('status', 'Added, switched off. Test it before switching it on.');
    }

    /**
     * Read the address the way the crawler would and report what happened.
     */
    public function test(int $id): RedirectResponse
    {
        $source = DB::table('sources')->where('id', $id)->first();

        if (!$source) {
            throw new NotFoundHttpException('No such source');
        }

        $url = $source->rss_url ?: ($source->index_url ?: $source->base_url);
        $result = $this->probe->probe((string) $url, $source->source_kind ?: 'rss');

        DB::table('sources')->where('id', $source->id)->update([
            'last_probed_at' => now(),
            'probe_notes'    => mb_substr($result['message'], 0, 500),
            'updated_at'     => now(),
        ]);

        return back()->with('probe', $result + ['id' => $source->id, 'url' => $url]);
    }

    /** Save one source's notes and schedule. */
    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'expect_note'            => ['nullable', 'string', 'max:4000'],
            'extract_note'           => ['nullable', 'string', 'max:4000'],
            'tech_note'              => ['nullable', 'string', 'max:4000'],
            'extraction_strategy'    => ['nullable', 'string', 'in:feed_only,page_only,wp_json,teaser_ok'],
            'fetch_interval_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'fetch_at_hour'          => ['nullable', 'integer', 'min:0', 'max:23'],
            'is_active'              => ['nullable', 'boolean'],
        ]);

        $source = DB::table('sources')->where('id', $id)->first();

        if (!$source) {
            throw new NotFoundHttpException('No such source');
        }

        DB::table('sources')->where('id', $id)->update([
            'expect_note'            => $data['expect_note'] ?: null,
            'extract_note'           => $data['extract_note'] ?: null,
            'tech_note'              => $data['tech_note'] ?: null,
            'extraction_strategy'    => $data['extraction_strategy'] ?: null,
            'fetch_interval_minutes' => $data['fetch_interval_minutes'] ?? null,
            'fetch_at_hour'          => $data['fetch_at_hour'] ?? null,
            'is_active'              => $request->boolean('is_active'),
            'notes_updated_at'       => now(),
            'updated_at'             => now(),
        ]);

        return back()->with('status', 'Saved: ' . $source->name);
    }

    /**
     * Remove a source, optionally for good.
     *
     * Deleting alone is not enough: discovery learns sources from what the
     * aggregator cites and from feeds declared on publishers' pages, so a junk
     * address deleted today is back next week. "Never visit again" is what
     * makes the removal stick.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'block'  => ['nullable', 'boolean'],
            'scope'  => ['nullable', 'in:url,host'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $source = DB::table('sources')->where('id', $id)->first();

        if (!$source) {
            throw new NotFoundHttpException('No such source');
        }

        $parent = $source->parent_source_id;
        $url = $source->rss_url ?: ($source->index_url ?: $source->base_url);

        if ($request->boolean('block')) {
            $this->blocklist->block($url, $data['scope'] ?? 'url', $data['reason'] ?? null, Auth::guard('admin')->id());
        }

        // A publisher's sections go with it; leaving them orphaned hides them
        // from every page while the crawler carries on reading them.
        DB::table('sources')->where('parent_source_id', $source->id)->delete();
        DB::table('sources')->where('id', $source->id)->delete();

        $message = $request->boolean('block')
            ? 'Removed, and it will not be visited again.'
            : 'Removed. Discovery may find it again — use "never visit again" to stop that.';

        return $parent
            ? redirect()->route('admin.sources.show', ['id' => $parent])->with('status', $message)
            : redirect()->route('admin.sources.index', ['country' => $source->country])->with('status', $message);
    }

    /**
     * Where effort is worth spending, split by how the source is failing.
     *
     * A feed that stops answering is loud. A feed that answers while the
     * article text never arrives is silent - the stories keep coming and every
     * one is judged on a teaser - and it is the one that cost this project
     * months, so both are shown.
     */
    public function failing(): View
    {
        $feedFailures = DB::table('sources')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('consecutive_failures', '>', 0)
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('last_status')->whereNotIn('last_status', ['ok']);
                  });
            })
            ->orderByDesc('consecutive_failures')
            ->get();

        // Anything under 400 characters is a teaser, not an article.
        $textFailures = DB::table('extraction_jobs as e')
            ->join('news_items as n', 'n.id', '=', 'e.news_item_id')
            ->where('n.published_at', '>=', now()->subDays(7))
            ->groupBy('n.source')
            ->havingRaw("count(*) FILTER (WHERE e.extraction_status = 'success') = 0
                         OR avg(length(coalesce(e.extracted_text, ''))) < 400")
            ->orderByRaw('count(*) DESC')
            ->get([
                DB::raw('n.source AS source'),
                DB::raw('count(*) AS total'),
                DB::raw("count(*) FILTER (WHERE e.extraction_status = 'success') AS ok"),
                DB::raw("count(*) FILTER (WHERE e.extraction_status = 'fallback_used') AS fallback"),
                DB::raw("count(*) FILTER (WHERE e.extraction_status = 'failed') AS failed"),
                DB::raw("round(avg(length(coalesce(e.extracted_text, ''))))::int AS avg_chars"),
            ]);

        return view('admin.sources.failing', [
            'feedFailures' => $feedFailures,
            'textFailures' => $textFailures,
        ]);
    }

    /** The do-not-visit list. */
    public function blocked(): View
    {
        return view('admin.sources.blocked', [
            'blocked' => DB::table('blocked_urls')->orderByDesc('created_at')->get(),
        ]);
    }

    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url'    => ['required', 'url', 'max:900'],
            'scope'  => ['required', 'in:url,host'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $this->blocklist->block($data['url'], $data['scope'], $data['reason'] ?? null, Auth::guard('admin')->id());

        return back()->with('status', 'Added to the do-not-visit list.');
    }

    public function unblock(int $id): RedirectResponse
    {
        $this->blocklist->unblock($id);

        return back()->with('status', 'Removed from the list. Discovery may find it again.');
    }

    /** The country this admin is allowed to be looking at. */
    private function countryFor(?string $requested): string
    {
        $mine = Auth::guard('admin')->user()->country ?? null;

        if ($mine) {
            return mb_strtoupper($mine);
        }

        $country = mb_strtoupper(trim((string) $requested));

        return preg_match('/^[A-Z]{2}$/', $country) ? $country : 'MY';
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    }

    private function recentFrom(object $root, $sections): array
    {
        $names = collect([$root->name])
            ->merge(collect($sections)->pluck('name'))
            ->unique()
            ->values()
            ->all();

        return DB::table('news_items')
            ->whereIn('source', $names)
            ->orderByDesc('published_at')
            ->limit(8)
            ->get(['id', 'title', 'published_at', 'ai_status', 'primary_category'])
            ->all();
    }
}

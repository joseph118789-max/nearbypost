<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Geo\Boundaries\Iso3166;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where the news IS - by country, then by state, then by who sent it.
 *
 * The Sources page next door is where the PUBLISHERS are. This is the other
 * axis: a Malaysian paper's report from Kathmandu counts here under Nepal,
 * and a wire story about Johor counts under Malaysia, Johor, whoever carried
 * it. Both readings are structural - a pin's polygon, or the country the
 * place text and the masthead give - never the publisher's address.
 *
 * Three levels, each a click deeper: countries; one country's states with a
 * count per day; one state's sources.
 */
class CountriesReportController extends Controller
{
    /** How many days the state grid shows. */
    private const DAYS = 14;

    /** The country a story belongs to: its pin, else what it claims. */
    private const COUNTRY = "coalesce(n.geo_country_code, n.geo_claim_country)";

    /** The state a story belongs to: its pin's, else what its text or its survivor gives. */
    private const STATE = "coalesce(n.geo_state_code, n.geo_claim_state)";

    /** The code a story spanning several states carries. */
    public const MULTI = 'MULTI';

    private const DAY = "(n.published_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Kuala_Lumpur')::date";

    /**
     * The two master tabs.
     *
     *   raw   everything ingested - duplicates, discards, the unclassified,
     *         all of it. What arrived.
     *   live  only stories a reader can see right now: an active row in the
     *         feed. What got through.
     *
     * Same layout, same three levels; the difference between the two is the
     * pipeline. A state with 60 raw and 12 live is a state whose news is
     * mostly duplicates or discards, and that is worth knowing per state.
     */
    private function tab(Request $request): string
    {
        return $request->query('view') === 'live' ? 'live' : 'raw';
    }

    private function scope(string $tab): string
    {
        return $tab === 'live'
            ? " and exists (select 1 from feed_ready_items f where f.news_item_id = n.id and f.is_active)"
            : '';
    }

    public function index(Request $request): View
    {
        $days = max(7, min(365, (int) $request->query('days', 30)));
        $tab  = $this->tab($request);
        $sc   = $this->scope($tab);

        $rows = DB::select("
            select " . self::COUNTRY . " as iso3,
                   count(*)                                                   as stories,
                   count(*) filter (where n.geo_country_code is not null)     as pinned,
                   count(*) filter (where exists (select 1 from feed_ready_items f
                                     where f.news_item_id = n.id and f.is_active)) as live,
                   count(*) filter (where n.ai_status = 'discarded')          as discarded,
                   count(distinct " . self::STATE . ")                        as states,
                   count(distinct n.source)                                  as sources,
                   max(n.published_at)                                       as latest
            from news_items n
            where n.published_at >= now() - (? || ' days')::interval
              and " . self::COUNTRY . " is not null {$sc}
            group by 1
            order by 2 desc
        ", [$days]);

        $nowhere = DB::selectOne("
            select count(*) as n from news_items n
            where n.published_at >= now() - (? || ' days')::interval
              and " . self::COUNTRY . " is null {$sc}", [$days])->n;

        // Every country the boundary table knows, not only those with news.
        // A country with none is a row of zeros; the absence is the point.
        $seen = array_column($rows, 'iso3');

        foreach (DB::table('boundaries')->where('level', 0)->orderBy('name')->get(['iso3', 'name']) as $b) {
            if (!in_array($b->iso3, $seen, true)) {
                $rows[] = (object) ['iso3' => $b->iso3, 'stories' => 0, 'pinned' => 0, 'live' => 0,
                                    'discarded' => 0, 'states' => 0, 'sources' => 0, 'latest' => null];
            }
        }

        foreach ($rows as $r) {
            $r->name = Iso3166::name($r->iso3) ?? $r->iso3;
            $r->has_states = DB::table('boundaries')->where('iso3', $r->iso3)->where('level', 1)->exists();
        }

        usort($rows, fn ($a, $b) => [$b->stories, $a->name] <=> [$a->stories, $b->name]);

        return view('admin.countries.index', [
            'view'    => $tab,
            'rows'    => $rows,
            'days'    => $days,
            'nowhere' => (int) $nowhere,
            'total'   => array_sum(array_column($rows, 'stories')),
        ]);
    }

    /** One country: its states, a count per day. */
    public function country(Request $request, string $iso3): View
    {
        $iso3 = strtoupper($iso3);
        $name = Iso3166::name($iso3);

        abort_if($name === null, 404);

        $days = max(7, min(90, (int) $request->query('days', self::DAYS)));
        $tab  = $this->tab($request);
        $sc   = $this->scope($tab);

        // Every state the boundary table knows, so a state with no news still
        // appears as a row of zeros - the absence is the information.
        $states = DB::table('boundaries')->where('iso3', $iso3)->where('level', 1)
            ->orderBy('name')->get(['code', 'name'])->keyBy('code');

        $cells = DB::select("
            select coalesce(" . self::STATE . ", '') as state, " . self::DAY . " as day, count(*) as n,
                   count(*) filter (where exists (select 1 from feed_ready_items f
                                     where f.news_item_id = n.id and f.is_active)) as live
            from news_items n
            where " . self::COUNTRY . " = ?
              and n.published_at >= now() - (? || ' days')::interval {$sc}
            group by 1, 2
        ", [$iso3, $days]);

        // Today first. The columns that matter are the ones just filed, and
        // they should be visible without scrolling to the far end.
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = now('Asia/Kuala_Lumpur')->subDays($i)->toDateString();
        }

        $grid = [];   // state code => [day => n]
        $tot  = [];   // state code => total

        foreach ($cells as $c) {
            $grid[$c->state][$c->day] = (int) $c->n;
            $tot[$c->state] = ($tot[$c->state] ?? 0) + (int) $c->n;
        }

        // Rows: known states in name order, then anything with news the table
        // has no polygon for, then the stories with no specific place.
        $rowsOut = [];

        foreach ($states as $code => $s) {
            $rowsOut[] = ['code' => $code, 'name' => $s->name, 'total' => $tot[$code] ?? 0, 'known' => true];
        }

        foreach ($tot as $code => $n) {
            if ($code !== '' && $code !== self::MULTI && !isset($states[$code])) {
                $rowsOut[] = ['code' => $code, 'name' => $code, 'total' => $n, 'known' => false];
            }
        }

        usort($rowsOut, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        // Several states at once - a haze reading, a highway crash on a state
        // line, an audit across Felda estates. Not one state's story and not
        // a national one either.
        $rowsOut[] = ['code' => self::MULTI, 'name' => 'Multiple states', 'total' => $tot[self::MULTI] ?? 0, 'known' => true];
        $rowsOut[] = ['code' => '', 'name' => 'No specific place (national, or not placed)', 'total' => $tot[''] ?? 0, 'known' => true];

        return view('admin.countries.country', [
            'view'  => $tab,
            'iso3'  => $iso3,
            'name'  => $name,
            'dates' => $dates,
            'grid'  => $grid,
            'rows'  => $rowsOut,
            'days'  => $days,
            'total' => array_sum($tot),
        ]);
    }

    /** One state: who sent the news, and what became of it. */
    public function state(Request $request, string $iso3, string $state, ?string $city = null): View
    {
        $iso3 = strtoupper($iso3);
        $name = Iso3166::name($iso3);

        abort_if($name === null, 404);

        $cityName = $city === null ? null
            : (DB::table('boundaries')->where('iso3', $iso3)->where('level', 2)->where('code', $city)->value('name') ?? abort(404));

        $days = max(7, min(90, (int) $request->query('days', 30)));
        $tab  = $this->tab($request);

        // One day, when a cell of the grid was clicked: "what makes up the 34".
        // A date that does not parse is refused rather than quietly widened.
        $day = null;

        if ($request->filled('day')) {
            try {
                $day = \Carbon\Carbon::createFromFormat('Y-m-d', (string) $request->query('day'))->toDateString();
            } catch (\Throwable $e) {
                abort(404);
            }

            abort_if($day !== (string) $request->query('day'), 404);
        }

        $stateName = match (true) {
            $state === '-'         => 'No specific place',
            $state === self::MULTI => 'Multiple states',
            default                => DB::table('boundaries')->where('iso3', $iso3)->where('level', 1)->where('code', $state)->value('name') ?? $state,
        };

        $stateWhere = $state === '-' ? self::STATE . " is null" : self::STATE . " = ?";
        $binds = $state === '-' ? [$iso3] : [$iso3, $state];

        if ($city !== null) {
            $stateWhere .= " and n.geo_city_code = ?";
            $binds[] = $city;
        }

        $stateWhere .= $this->scope($tab);

        // Either the window, or exactly one Malaysian day.
        if ($day !== null) {
            $stateWhere .= " and " . self::DAY . " = ?";
            $binds[] = $day;
        } else {
            $stateWhere .= " and n.published_at >= now() - (? || ' days')::interval";
            $binds[] = $days;
        }

        $rows = DB::select("
            select n.source,
                   count(*)                                                   as stories,
                   count(*) filter (where exists (select 1 from feed_ready_items f
                                     where f.news_item_id = n.id and f.is_active)) as live,
                   count(*) filter (where n.ai_status = 'discarded')          as discarded,
                   count(*) filter (where n.ai_status = 'duplicate')          as duplicate,
                   count(*) filter (where n.geo_country_code is not null)     as pinned,
                   max(n.published_at)                                       as latest
            from news_items n
            where " . self::COUNTRY . " = ?
              and {$stateWhere}
            group by 1
            order by 2 desc
        ", $binds);

        $recent = DB::select("
            select n.id, n.title, n.source, n.url, n.published_at, n.canonical_place_name, n.ai_status,
                   exists (select 1 from feed_ready_items f where f.news_item_id = n.id and f.is_active) as live
            from news_items n
            where " . self::COUNTRY . " = ?
              and {$stateWhere}
            order by n.published_at desc
            limit " . ($day !== null ? 200 : 40) . "
        ", $binds);

        // The districts of this state, a count per day, when the country has
        // been loaded to that depth. The same grid as the country page, one
        // level down; each number opens the district's sources.
        $cities = [];
        $cityGrid = [];
        $dates = [];

        if ($city === null && $state !== '-' && $state !== self::MULTI && $day === null) {
            $known = DB::table('boundaries')->where('iso3', $iso3)->where('level', 2)->where('parent_code', $state)
                ->orderBy('name')->get(['code', 'name'])->keyBy('code');

            if ($known->isNotEmpty()) {
                $gridDays = max(7, min(60, (int) $request->query('days', self::DAYS)));

                for ($i = 0; $i < $gridDays; $i++) {
                    $dates[] = now('Asia/Kuala_Lumpur')->subDays($i)->toDateString();
                }

                $cells = DB::select("
                    select coalesce(n.geo_city_code, '') as city, " . self::DAY . " as day, count(*) as n
                    from news_items n
                    where " . self::COUNTRY . " = ? and " . self::STATE . " = ?
                      and n.published_at >= now() - (? || ' days')::interval
                    group by 1, 2", [$iso3, $state, $gridDays]);

                $tot = [];

                foreach ($cells as $c) {
                    $cityGrid[$c->city][$c->day] = (int) $c->n;
                    $tot[$c->city] = ($tot[$c->city] ?? 0) + (int) $c->n;
                }

                foreach ($known as $code => $k) {
                    $cities[] = ['code' => $code, 'name' => $k->name, 'total' => $tot[$code] ?? 0];
                }

                usort($cities, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
                $cities[] = ['code' => '', 'name' => 'Not placed to a district', 'total' => $tot[''] ?? 0];
            }
        }

        return view('admin.countries.state', [
            'view'      => $tab,
            'iso3'      => $iso3,
            'name'      => $name,
            'state'     => $state,
            'stateName' => $stateName,
            'city'      => $city,
            'cityName'  => $cityName,
            'cities'    => $cities,
            'cityGrid'  => $cityGrid,
            'dates'     => $dates,
            'rows'      => $rows,
            'recent'    => $recent,
            'days'      => $days,
            'day'       => $day,
            'total'     => array_sum(array_column($rows, 'stories')),
        ]);
    }
}

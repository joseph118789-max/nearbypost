<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * The taxonomy as it really is: the `categories` and `subcategories` tables
 * the classifier and the site read, with how many live stories sit under
 * each. The old Settings modal showed a JSON file and saved to a cache key
 * nothing reads; this is the page that shows the truth.
 */
class TaxonomyController extends Controller
{
    public function index(): View
    {
        $days = 30;

        $liveByCategory = DB::table('feed_ready_items')->where('is_active', true)->where('published_at', '>=', now()->subDays($days))
            ->selectRaw('lower(primary_category) as k, count(*) as n')->groupBy('k')->pluck('n', 'k')->all();

        $liveBySub = DB::table('feed_ready_items')->where('is_active', true)->where('published_at', '>=', now()->subDays($days))
            ->whereNotNull('sub_category')
            ->selectRaw('lower(primary_category) as c, lower(sub_category) as s, count(*) as n')->groupBy('c', 's')->get()
            ->keyBy(fn ($r) => $r->c . '|' . $r->s)->map(fn ($r) => (int) $r->n)->all();

        $subs = DB::table('subcategories')->orderBy('primary_category')->orderBy('id')->get()
            ->groupBy(fn ($s) => mb_strtolower($s->primary_category));

        $categories = DB::table('categories')->orderBy('id')->get()->map(function ($c) use ($subs, $liveByCategory, $liveBySub) {
            $key = mb_strtolower($c->name);
            $list = ($subs[$key] ?? collect())->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->sub_category,
                'ms'         => $s->sub_category_ms,
                'zh'         => $s->sub_category_zh,
                'weight'     => $s->weight,
                'gps'        => $s->gps,
                'created_by' => $s->created_by,
                'live'       => $liveBySub[$key . '|' . mb_strtolower($s->sub_category)] ?? 0,
            ])->sortByDesc('live')->values()->all();

            return [
                'id'     => $c->id,
                'name'   => $c->name,
                'ms'     => $c->name_ms,
                'zh'     => $c->name_zh,
                'weight' => $c->weight,
                'gps'    => $c->gps,
                'live'   => $liveByCategory[$key] ?? 0,
                'subs'   => $list,
            ];
        })->sortByDesc('live')->values()->all();

        // Sub-categories stories carry that the table does not know - the
        // classifier wrote them before they were registered, or the spelling
        // drifted. Worth seeing: they are on the site under a name nobody set.
        $known = [];
        foreach ($categories as $c) {
            foreach ($c['subs'] as $s) {
                $known[mb_strtolower($c['name']) . '|' . mb_strtolower($s['name'])] = true;
            }
        }

        $unregistered = [];
        foreach ($liveBySub as $k => $n) {
            if (!isset($known[$k])) {
                [$c, $s] = explode('|', $k, 2);
                $unregistered[] = ['category' => $c, 'sub' => $s, 'live' => $n];
            }
        }
        usort($unregistered, fn ($a, $b) => $b['live'] <=> $a['live']);

        return view('admin.brain.taxonomy', [
            'categories'   => $categories,
            'unregistered' => $unregistered,
            'days'         => $days,
            'totalSubs'    => DB::table('subcategories')->count(),
        ]);
    }
}

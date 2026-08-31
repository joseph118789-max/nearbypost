<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The handbook: where the news comes from, and what we know about getting it.
 *
 * Two levels, because that is the shape of the thing. A publisher is one entry;
 * underneath it are the section URLs - sports, business, property - that each
 * behave slightly differently and each fill a different part of the site.
 *
 * Every source carries three notes in plain prose rather than a row of flags,
 * because they are written for three readers who need different things: an
 * editor deciding whether a source is worth keeping, whoever is setting the
 * crawl rules, and the model that will be asked to repair the crawler after
 * everyone who remembers it has moved on.
 *
 * And a schedule, because a property section does not need visiting every
 * fifteen minutes and visiting it anyway spends requests against publishers who
 * can block us for it.
 */
class SourceController extends Controller
{
    /** Offered in the panel; anything else typed in is still accepted. */
    public const INTERVALS = [
        15   => 'Every 15 minutes',
        30   => 'Every 30 minutes',
        60   => 'Hourly',
        180  => 'Every 3 hours',
        360  => 'Every 6 hours',
        720  => 'Twice a day',
        1440 => 'Once a day',
    ];

    /** The first level: one row per publisher. */
    public function index(): View
    {
        $roots = DB::table('sources')
            ->whereNull('parent_source_id')
            ->orderByDesc('is_active')
            ->orderByDesc('items_contributed')
            ->orderBy('name')
            ->get();

        // One query for every child, counted and summed per parent, rather than
        // one query per row.
        $children = DB::table('sources')
            ->whereNotNull('parent_source_id')
            ->selectRaw('parent_source_id, count(*) AS sections,
                         count(*) FILTER (WHERE is_active) AS active_sections,
                         coalesce(sum(items_contributed), 0) AS items')
            ->groupBy('parent_source_id')
            ->get()
            ->keyBy('parent_source_id');

        return view('admin.sources.index', [
            'roots'    => $roots,
            'children' => $children,
            'orphans'  => $this->orphanCount(),
        ]);
    }

    /** The second level: one publisher, and each of its section URLs. */
    public function show(int $id): View
    {
        $source = DB::table('sources')->where('id', $id)->first();

        if (!$source) {
            throw new NotFoundHttpException('No such source');
        }

        // Always show the publisher, even when a section was opened directly.
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
        ]);
    }

    /** Save one source's notes and schedule. */
    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'expect_note'            => ['nullable', 'string', 'max:4000'],
            'extract_note'           => ['nullable', 'string', 'max:4000'],
            'tech_note'              => ['nullable', 'string', 'max:4000'],
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
            'fetch_interval_minutes' => $data['fetch_interval_minutes'] ?? null,
            'fetch_at_hour'          => $data['fetch_at_hour'] ?? null,
            'is_active'              => $request->boolean('is_active'),
            'notes_updated_at'       => now(),
            'updated_at'             => now(),
        ]);

        return back()->with('status', 'Saved: ' . $source->name);
    }

    /**
     * The last few stories this publisher actually produced.
     *
     * The notes say what to expect; this says what turned up. When the two
     * disagree, the notes are out of date.
     */
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

    /** Sections whose parent has been deleted - visible nowhere else. */
    private function orphanCount(): int
    {
        return DB::table('sources as s')
            ->whereNotNull('s.parent_source_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('sources as p')
                  ->whereColumn('p.id', 's.parent_source_id');
            })
            ->count();
    }
}

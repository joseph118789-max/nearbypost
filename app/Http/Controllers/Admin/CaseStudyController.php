<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedReadyItem;
use App\Models\NewsItem;
use App\Services\Contribution\OutletFinder;
use App\Services\LocationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A story that is happening in many places at once.
 *
 * "Harvey Norman is running 50% off nationwide" is true at every branch, so
 * pinning it to one of them means nobody near the other nineteen ever sees it.
 * The editor writes the story once; the model proposes where it applies; the
 * editor corrects that list; and the story is then served to whoever is nearest
 * to any of those places, once.
 *
 * The correcting step is not a formality and is not skippable. A model asked to
 * list a retailer's branches will produce a plausible list containing branches
 * that closed, branches in the wrong mall, and branches that never existed.
 * Publishing that unread would put this site's name on made-up facts about real
 * businesses, so nothing is published until a person has been through the list.
 */
class CaseStudyController extends Controller
{
    public function __construct(
        private OutletFinder $finder,
        private LocationResolver $locations,
    ) {
    }

    public function index(): View
    {
        $cases = NewsItem::query()
            ->where('is_multi_point', true)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $counts = DB::table('story_locations')
            ->selectRaw('news_item_id, count(*) AS total,
                         count(*) FILTER (WHERE lat IS NOT NULL) AS placed')
            ->groupBy('news_item_id')
            ->get()
            ->keyBy('news_item_id');

        return view('admin.cases.index', ['cases' => $cases, 'counts' => $counts]);
    }

    public function create(): View
    {
        return view('admin.cases.create');
    }

    /**
     * Write the story down and ask where it applies, but publish nothing.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:8', 'max:200'],
            'body'  => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        $case = NewsItem::create([
            'title'          => $data['title'],
            'body'           => $data['body'],
            'summary'        => mb_substr($data['body'], 0, 500),
            'source'         => 'Nearbypost',
            'origin'         => 'editorial',
            'is_multi_point' => true,
            'section'        => 'interest',
            'url'            => 'draft:' . bin2hex(random_bytes(8)),
            'published_at'   => now(),
            'status'         => 'held',
            'review_status'  => 'pending_review',
            'ai_status'      => 'pending',
        ]);

        $case->update(['url' => url('/post/' . $case->id)]);

        $proposal = $this->finder->propose($data['title'], $data['body']);

        $seen = [];

        foreach ($proposal['outlets'] as $outlet) {
            $label = $this->tidyPlace($outlet['place']);
            $key = mb_strtolower($label);

            // The model listed Kuala Lumpur twice. Two serving rows at one
            // point is one row of waste: the reader is shown the story once
            // whichever of them is nearest.
            if ($label === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            DB::table('story_locations')->insert([
                'news_item_id' => $case->id,
                'label'        => $label,
                'added_by'     => 'ai',
                // The model's own doubt, kept where the editor will see it.
                'geocode_status' => $outlet['confident'] ? null : 'unsure',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        return redirect()
            ->route('admin.cases.show', ['id' => $case->id])
            ->with('status', $proposal['note'] !== ''
                ? $proposal['note']
                : 'Check every place below before publishing.');
    }

    public function show(int $id): View
    {
        $case = $this->caseStudy($id);

        return view('admin.cases.show', [
            'case'      => $case,
            'locations' => DB::table('story_locations')
                ->where('news_item_id', $case->id)
                ->orderBy('id')
                ->get(),
            'served'    => FeedReadyItem::where('news_item_id', $case->id)
                ->where('is_active', true)
                ->count(),
        ]);
    }

    /** Add one place by hand. */
    public function addPlace(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:190'],
        ]);

        $case = $this->caseStudy($id);

        DB::table('story_locations')->insert([
            'news_item_id' => $case->id,
            'label'        => $this->tidyPlace($data['label']),
            'added_by'     => 'manual',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return back()->with('status', 'Added. Put it on the map before publishing.');
    }

    public function removePlace(int $id, int $placeId): RedirectResponse
    {
        $case = $this->caseStudy($id);

        DB::table('story_locations')
            ->where('news_item_id', $case->id)
            ->where('id', $placeId)
            ->delete();

        return back()->with('status', 'Removed.');
    }

    /**
     * Put every place on the map.
     *
     * Separate from publishing on purpose: geocoding is slow - the provider
     * allows roughly one request a second - and an editor should see which
     * places could not be found before deciding whether to go ahead without
     * them.
     */
    public function geocode(int $id): RedirectResponse
    {
        $case = $this->caseStudy($id);

        $found = 0;
        $missed = 0;

        foreach (DB::table('story_locations')->where('news_item_id', $case->id)->get() as $place) {
            if ($place->lat !== null) {
                continue;
            }

            $coords = $this->locations->resolve($place->label);

            DB::table('story_locations')->where('id', $place->id)->update([
                'lat'            => $coords['lat'] ?? null,
                'lng'            => $coords['lng'] ?? null,
                'geocode_status' => $coords ? 'found' : 'not_found',
                'updated_at'     => now(),
            ]);

            $coords ? $found++ : $missed++;
        }

        return back()->with('status', $missed === 0
            ? "Placed {$found} location(s)."
            : "Placed {$found}; {$missed} could not be found and will not be served. Correct or remove them.");
    }

    /**
     * Serve it: one row per located place, and the reader sees it once.
     */
    public function publish(Request $request, int $id): RedirectResponse
    {
        $case = $this->caseStudy($id);

        $data = $request->validate([
            'primary_category' => ['required', 'string', 'max:60'],
        ]);

        $places = DB::table('story_locations')
            ->where('news_item_id', $case->id)
            ->whereNotNull('lat')
            ->orderBy('id')
            ->get();

        if ($places->isEmpty()) {
            return back()->withErrors(['primary_category' => 'Nothing is on the map yet, so there is nowhere to serve this.']);
        }

        $case->update([
            'primary_category' => mb_strtolower($data['primary_category']),
            'ai_category'      => mb_strtolower($data['primary_category']),
            'status'           => 'active',
            'review_status'    => 'published',
            'relevance_mode'   => 'location_and_category',
            'ai_status'        => 'success',
            'is_article'       => true,
            'main_place_text'  => $places->first()->label,
            'location_label'   => $places->first()->label,
            'latitude'         => $places->first()->lat,
            'longitude'        => $places->first()->lng,
            'precision_type'   => 'approximate',
        ]);

        // Rewritten wholesale rather than merged: the list of places is the
        // editor's, and a place they deleted must not survive in the feed.
        FeedReadyItem::where('news_item_id', $case->id)->delete();

        foreach ($places as $i => $place) {
            FeedReadyItem::create([
                'news_item_id'        => $case->id,
                'title'               => $case->title,
                'summary'             => $case->summary,
                'source'              => $case->source,
                'url'                 => $case->url,
                'published_at'        => $case->published_at,
                'primary_category'    => $case->primary_category,
                'location_label'      => $place->label,
                'lat'                 => $place->lat,
                'lng'                 => $place->lng,
                'precision_type'      => 'approximate',
                'relevance_mode'      => 'location_and_category',
                'sort_timestamp'      => $case->published_at,
                'canonical_place_name' => $place->label,
                'origin'              => 'editorial',
                'is_article'          => true,
                'is_active'           => true,
                // Exactly one row carries the flag, so the feeds that do not
                // sort by distance show the story once without a subquery.
                'is_primary_location' => $i === 0,
            ]);
        }

        return back()->with('status', 'Published at ' . $places->count() . ' location(s).');
    }

    /** Take it out of the feed everywhere at once. */
    public function unpublish(Request $request, int $id): RedirectResponse
    {
        $case = $this->caseStudy($id);

        $reason = trim((string) $request->input('reason'));

        $case->update([
            'status'        => 'held',
            'review_status' => 'rejected',
            'review_reason' => mb_substr($reason ?: 'Taken down by an editor.', 0, 300),
        ]);

        FeedReadyItem::where('news_item_id', $case->id)->delete();

        DB::table('removals')->insert([
            'news_item_id'     => $case->id,
            'title'            => mb_substr((string) $case->title, 0, 500),
            'origin'           => 'editorial',
            'source'           => $case->source,
            'primary_category' => $case->primary_category,
            'reason'           => $reason ?: 'Taken down by an editor, no reason given.',
            'removed_by'       => Auth::guard('admin')->id(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return back()->with('status', 'Taken down from every location.');
    }

    /**
     * Name a place the way a reader would type it.
     *
     * The model returns "town, state", which for a federal territory comes back
     * as "Kuala Lumpur, Kuala Lumpur". That resolves to a different point from
     * the plain "Kuala Lumpur" a reader types - close enough to look right on a
     * map, far enough to rank behind every story pinned to the city centre.
     */
    private function tidyPlace(string $label): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $label))));

        if ($parts === []) {
            return '';
        }

        // Drop any segment that simply repeats the one before it.
        $tidied = [$parts[0]];

        for ($i = 1; $i < count($parts); $i++) {
            if (mb_strtolower($parts[$i]) !== mb_strtolower($parts[$i - 1])) {
                $tidied[] = $parts[$i];
            }
        }

        return mb_substr(implode(', ', $tidied), 0, 190);
    }

    private function caseStudy(int $id): NewsItem
    {
        $case = NewsItem::where('id', $id)->where('is_multi_point', true)->first();

        if (!$case) {
            throw new NotFoundHttpException('No such case study');
        }

        return $case;
    }
}

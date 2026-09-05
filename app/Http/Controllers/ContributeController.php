<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
use App\Services\Contribution\ImageIntake;
use App\Services\Contribution\PostPublisher;
use App\Services\FeedQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A contributor's own corner: write a story, see what happened to it, fix it,
 * withdraw it.
 *
 * Everything here is scoped to the signed-in contributor by contributor_id
 * rather than by what the URL asks for, so there is no id a person can change
 * to reach someone else's post.
 *
 * Editing re-submits. A post that has been rewritten is not the post that was
 * judged, so it goes back through the review rather than keeping a verdict
 * earned by different words - otherwise a contributor could pass a harmless
 * story and then edit it into anything.
 */
class ContributeController extends Controller
{
    private const SECTIONS = ['nearme', 'interest', 'marketplace'];

    public function __construct(
        private FeedQuery $feed,
        private PostPublisher $publisher,
        private ImageIntake $images,
    ) {
    }

    /** Everything this contributor has written, newest first. */
    public function index(): View
    {
        $posts = NewsItem::query()
            ->where('contributor_id', Auth::id())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('pages.contribute.index', $this->chrome(__('site.my_posts')) + [
            'posts' => $posts,
        ]);
    }

    public function create(): View
    {
        return view('pages.contribute.form', $this->chrome(__('site.write_a_post')) + [
            'post'     => new NewsItem(['section' => 'nearme']),
            'sections' => self::SECTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->throttleSubmissions($request);

        $data = $this->validated($request);
        $pin  = $this->pin($request);

        $post = new NewsItem([
            'title'           => $data['title'],
            'body'            => $data['body'],
            'section'         => $data['section'],
            'main_place_text' => $data['place'] ?? null,
            'origin'          => 'user',
            'contributor_id'  => Auth::id(),
            'source'          => Auth::user()->name,
            'published_at'    => now(),
            // news_items.url is UNIQUE and the real address is not known until
            // the row has an id, so the placeholder has to be unique too.
            'url'             => 'draft:' . Str::uuid(),
            'status'          => 'held',
            'ai_status'       => 'pending',
        ]);

        if ($request->hasFile('image')) {
            $post->image_path = $this->intake($request);
        }

        if ($pin !== null) {
            // the reader's pin is the story's place - no geocoding to wait for
            $post->latitude  = $pin['lat'];
            $post->longitude = $pin['lng'];
            $post->main_place_text = $data['place'] ?? $pin['label'] ?? null;
        }

        $post = $this->publisher->submit($post);

        // Community Reports: the photo's hashes, EXIF (private) and duplicates - before the editor sees it
        if ($request->hasFile('image') && $post->id && config('services.community.enabled')) {
            try {
                $img = \App\Services\Community\ImageChecks::analyse($request->file('image'), $post->id, $pin, $post->image_path);

                if ($img['duplicate_of'] && $post->status === 'active') {
                    $post->update(['status' => 'held', 'review_status' => 'pending_review', 'review_reason' => __('site.image_duplicate_held')]);
                    $post = $post->fresh();
                }
            } catch (\Throwable $x) {
                \Illuminate\Support\Facades\Log::warning('Image checks failed', ['news_item_id' => $post->id, 'error' => mb_substr($x->getMessage(), 0, 160)]);
            }
        }

        // Community Reports: the editor's pass (improved title and body,
        // facts preserved), the immutable original, the pin's provenance
        (new \App\Services\Community\CommunityPublisher())->afterSubmit($post, $data, $pin);

        if ($post->status === 'active' && $post->review_status === 'published' && config('services.community.enabled')) {
            return redirect()->route('post.show', ['id' => $post->id])->with('status', __('site.report_published'));
        }

        return redirect()
            ->route('contribute.index')
            ->with('status', $this->outcomeMessage($post));
    }

    /**
     * The pin from the create form: where the phone said the reader was,
     * where they put the pin, how far apart, and how the location came about.
     *
     * @return ?array{lat: float, lng: float, gps_lat: ?float, gps_lng: ?float, accuracy: ?int, adjusted: bool, source: string, label: ?string}
     */
    private function pin(Request $request): ?array
    {
        $lat = $request->input('pin_lat');
        $lng = $request->input('pin_lng');

        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return null;
        }

        $gpsLat = is_numeric($request->input('gps_lat')) ? (float) $request->input('gps_lat') : null;
        $gpsLng = is_numeric($request->input('gps_lng')) ? (float) $request->input('gps_lng') : null;

        return [
            'lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7),
            'gps_lat' => $gpsLat, 'gps_lng' => $gpsLng,
            'accuracy' => is_numeric($request->input('gps_accuracy')) ? (int) $request->input('gps_accuracy') : null,
            'adjusted' => $request->boolean('pin_adjusted'),
            'source' => in_array($request->input('location_source'), ['gps', 'manual', 'denied', 'timeout', 'unavailable'], true) ? $request->input('location_source') : 'manual',
            'label' => $request->filled('pin_label') ? mb_substr((string) $request->input('pin_label'), 0, 160) : null,
        ];
    }

    public function edit(int $id): View
    {
        $post = $this->own($id);

        return view('pages.contribute.form', $this->chrome(__('site.edit_post')) + [
            'post'     => $post,
            'sections' => self::SECTIONS,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->throttleSubmissions($request);

        $post = $this->own($id);
        $data = $this->validated($request);

        $post->fill([
            'title'           => $data['title'],
            'body'            => $data['body'],
            'section'         => $data['section'],
            'main_place_text' => $data['place'] ?? null,
        ]);

        if ($request->boolean('remove_image')) {
            $this->images->forget($post->image_path);
            $post->image_path = null;
        }

        if ($request->hasFile('image')) {
            $previous = $post->image_path;
            $post->image_path = $this->intake($request);
            $this->images->forget($previous);
        }

        $wasLive = $post->getOriginal('review_status') === 'published';
        $post = $this->publisher->submit($post);

        (new \App\Services\Community\CommunityPublisher())->afterSubmit($post, $data, $this->pin($request) ?? ($post->latitude !== null ? ['lat' => (float) $post->latitude, 'lng' => (float) $post->longitude, 'gps_lat' => null, 'gps_lng' => null, 'accuracy' => null, 'adjusted' => false, 'source' => 'manual', 'label' => $post->main_place_text] : null));

        if ($wasLive && $post->status === 'active') {
            \Illuminate\Support\Facades\DB::table('community_post_meta')->where('news_item_id', $post->id)->update(['last_materially_updated_at' => now(), 'updated_at' => now()]);
        }

        return redirect()
            ->route('contribute.index')
            ->with('status', $this->outcomeMessage($post));
    }

    public function destroy(int $id): RedirectResponse
    {
        $post = $this->own($id);

        // The picture goes with the post. Deleting the row alone would leave
        // the file reachable by anyone who had seen its address.
        $this->images->forget($post->image_path);

        // Withdraw it from the feed first, then remove it: the observer clears
        // the serving row on delete, and this makes the intent explicit.
        $post->update(['status' => 'withdrawn']);
        $post->delete();

        return redirect()
            ->route('contribute.index')
            ->with('status', __('site.post_deleted'));
    }

    /**
     * The contributor's own post, or a 404.
     *
     * Not "or a 403": telling a stranger that id 812 exists but is not theirs
     * is more than they need to know.
     */
    private function own(int $id): NewsItem
    {
        $post = NewsItem::where('id', $id)
            ->where('contributor_id', Auth::id())
            ->first();

        if (!$post) {
            throw new NotFoundHttpException('No such post');
        }

        return $post;
    }

    private function validated(Request $request): array
    {
        if (config('services.community.enabled')) {
            $request->merge(['section' => 'nearme']);   // a community report is always Near Me
        }

        return $request->validate([
            'title'   => ['required', 'string', 'min:8', 'max:200'],
            'body'    => ['required', 'string', 'min:40', 'max:5000'],
            'section' => [config('services.community.enabled') ? 'nullable' : 'required', 'string', 'in:' . implode(',', self::SECTIONS)],
            'place'   => ['nullable', 'string', 'max:160'],
            'image'   => [
                'nullable', 'file', 'image',
                'max:' . (int) (ImageIntake::MAX_UPLOAD_BYTES / 1024),
            ],
        ], [], [
            'body'  => __('site.field_story'),
            'place' => __('site.field_place'),
        ]);
    }

    private function intake(Request $request): string
    {
        try {
            return $this->images->store($request->file('image'));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }
    }

    /**
     * Writing costs a reviewer call, so it is rate limited per contributor.
     */
    private function throttleSubmissions(Request $request): void
    {
        $key = 'contribute:' . Auth::id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'title' => __('site.submit_throttled', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }

        RateLimiter::hit($key, 3600);
    }

    /** What actually happened, in the contributor's own terms. */
    private function outcomeMessage(NewsItem $post): string
    {
        return match ($post->review_status) {
            'published'            => __('site.post_published'),
            'pending_review'       => __('site.post_pending_review'),
            'awaiting_marketplace' => __('site.post_awaiting_marketplace'),
            'rejected'             => __('site.post_rejected', ['reason' => $post->review_reason]),
            default                => __('site.post_held'),
        };
    }

    private function chrome(string $title): array
    {
        return [
            'tab'        => null,
            'pageTitle'  => $title,
            'categories' => $this->feed->categories(),
        ];
    }
}

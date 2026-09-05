<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\User;
use App\Services\Contribution\ImageIntake;
use App\Services\Contribution\NewsworthinessReview;
use App\Services\FeedQuery;
use App\Support\Loc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The admin posts with the reader's own form - title, story, pin on the map,
 * photo - and it goes live at once (owner, 4 Sep 2026: "no vetting required
 * because it is posted directly as admin ... AI will help classify it but
 * won't moderate the image or text").
 *
 *   official   - shown as a publisher's story (origin editorial, the source is the publisher named)
 *   unofficial - shown as a reader post by @admin (origin user), with the community record
 */
class AdminPostController extends Controller
{
    public function __construct(private ImageIntake $images, private NewsworthinessReview $review, private FeedQuery $feed)
    {
    }

    public function create(string $kind): View
    {
        abort_unless(in_array($kind, ['official', 'unofficial'], true), 404);

        return view('admin.post-new', [
            'kind'        => $kind,
            'post'        => new NewsItem(['section' => 'nearme']),
            'sections'    => ['nearme'],
            'editing'     => false,
            'action'      => route('admin.post.store', ['kind' => $kind]),
            'submitLabel' => $kind === 'official' ? 'Publish as official news' : 'Publish as @admin',
            'adminKind'   => $kind,
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ['official', 'unofficial'], true), 404);

        $data = $request->validate([
            'title'     => ['required', 'string', 'min:8', 'max:200'],
            'body'      => ['required', 'string', 'min:40', 'max:5000'],
            'place'     => ['nullable', 'string', 'max:160'],
            'publisher' => ['nullable', 'string', 'max:120'],
            'image'     => ['nullable', 'file', 'image', 'max:' . (int) (ImageIntake::MAX_UPLOAD_BYTES / 1024)],
        ]);
        $pin = $this->pin($request);
        $adminId = (int) Auth::guard('admin')->id();
        $author = $kind === 'unofficial' ? $this->adminReader() : null;

        $post = new NewsItem([
            'title'           => $data['title'],
            'body'            => $data['body'],
            'section'         => 'nearme',
            'main_place_text' => $data['place'] ?? null,
            'origin'          => $kind === 'official' ? 'editorial' : 'user',
            'contributor_id'  => $author?->id,
            'source'          => $kind === 'official' ? (trim((string) ($data['publisher'] ?? '')) ?: 'Nearbypost') : $author->name,
            'published_at'    => now(),
            'url'             => 'draft:' . Str::uuid(),
            'status'          => 'active',
            'ai_status'       => 'pending',
        ]);

        if ($request->hasFile('image')) {
            try {
                $post->image_path = $this->images->store($request->file('image'));
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['image' => $e->getMessage()]);
            }
        }

        if ($pin !== null) {
            $post->latitude = $pin['lat'];
            $post->longitude = $pin['lng'];
            $post->main_place_text = $data['place'] ?? $pin['label'] ?? null;
        }

        // Classification only: category, sub-category, summary, place, translations. The verdict's
        // "is this news" is not consulted - the admin decided that by pressing the button.
        $verdict = ['ok' => true, 'category' => null, 'sub' => null, 'place' => null, 'summary' => null, 'translations' => []];
        try {
            $verdict = $this->review->review('nearme', $post->title, (string) $post->body, $post->main_place_text) + $verdict;
        } catch (\Throwable $e) {
            Log::warning('Admin post: classification failed, published unclassified', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        $place = $verdict['place'] ?: $post->main_place_text;
        $post->fill([
            'summary'          => $verdict['summary'] ?: mb_substr((string) $post->body, 0, 500),
            'ai_summary'       => $verdict['summary'],
            'primary_category' => $verdict['category'] ?: 'others',
            'ai_category'      => $verdict['category'] ?: 'others',
            'sub_category'     => $verdict['sub'],
            'main_place_text'  => $place,
            'review_status'    => 'published',
            'review_reason'    => 'published by the admin',
            'ai_status'        => 'success',
            'ai_model'         => 'admin-post',
            'ai_processed_at'  => now(),
            'is_article'       => true,
        ]);

        if ($post->latitude !== null && $post->longitude !== null) {
            $post->fill(['canonical_place_name' => $place, 'location_label' => $place, 'precision_type' => 'exact', 'geocode_status' => 'success',
                'geocode_provider' => $kind === 'official' ? 'admin_pin' : 'reader_pin', 'geocoded_at' => now(), 'relevance_mode' => 'location_and_category']);
        } else {
            $coords = $place ? app(\App\Services\LocationResolver::class)->resolve($place) : null;
            if ($coords) {
                $post->fill(['latitude' => $coords['lat'], 'longitude' => $coords['lng'], 'canonical_place_name' => $coords['label'] ?? $place, 'location_label' => $coords['label'] ?? $place,
                    'precision_type' => 'approximate', 'geocode_status' => 'success', 'geocoded_at' => now(), 'relevance_mode' => 'location_and_category']);
            } else {
                $post->fill(['relevance_mode' => 'category_only']);
            }
        }

        $post->save();
        $post->update(['url' => $kind === 'official' ? Loc::route('story', ['id' => $post->id]) : url('/post/' . $post->id)]);

        foreach ((array) $verdict['translations'] as $locale => $text) {
            if (!empty($text['title'])) {
                DB::table('news_translations')->updateOrInsert(['news_item_id' => $post->id, 'locale' => $locale],
                    ['title' => mb_substr($text['title'], 0, 550), 'summary' => $text['summary'] ?? null, 'model' => 'admin-post', 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        if ($kind === 'unofficial') {
            // the community record (reactions, comments, history) without the AI editor's pass
            (new \App\Services\Community\CommunityPublisher())->adopt($post->fresh(), $adminId);
            DB::table('community_post_meta')->where('news_item_id', $post->id)->update([
                'selected_lat' => $post->latitude, 'selected_lng' => $post->longitude, 'location_source' => $pin['source'] ?? 'manual', 'updated_at' => now(),
            ]);
        }

        return redirect()->to($post->fresh()->url)->with('status', $kind === 'official' ? 'Published as official news.' : 'Published as @admin.');
    }

    /** The reader account the admin posts unofficial news under: @admin, trusted. */
    private function adminReader(): User
    {
        $user = User::query()->where('username', 'admin')->first();

        if ($user) {
            return $user;
        }

        return User::query()->create([
            'name' => 'Admin', 'username' => 'admin', 'display_name' => 'Nearbypost Admin', 'email' => 'admin@nearbypost.com',
            'password' => Hash::make(Str::random(40)), 'status' => 'active', 'trusted_at' => now(), 'credibility' => 80, 'credibility_base' => 80,
            'email_verified_at' => now(), 'join_date' => now(),
        ]);
    }

    private function pin(Request $request): ?array
    {
        $lat = $request->input('pin_lat'); $lng = $request->input('pin_lng');

        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return null;
        }

        return ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7),
            'source' => in_array($request->input('location_source'), ['gps', 'manual', 'denied', 'timeout', 'unavailable'], true) ? $request->input('location_source') : 'manual',
            'label' => $request->filled('pin_label') ? mb_substr((string) $request->input('pin_label'), 0, 160) : null];
    }
}

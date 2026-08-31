<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedReadyItem;
use App\Models\NewsItem;
use App\Http\Requests\NewsItemRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NewsController extends Controller
{
    private function feedQuery()
    {
        // Admin feed shows all promoted active rows — do not exclude category_only.
        // All rows in feed_ready_items have passed the promotion gate.
        return FeedReadyItem::where('is_active', true);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->feedQuery();

        // Who wrote it. "Official" is everything not written by a reader
        // rather than 'scraper' exactly, matching the reader's own filter, so a
        // story from a future third origin stays ours instead of vanishing
        // from both lists.
        if ($request->filled('origin') && $request->origin !== 'all') {
            $request->origin === 'unofficial'
                ? $query->where('origin', '=', 'user')
                : $query->where('origin', '<>', 'user');
        }

        if ($request->filled('primary_cat') && $request->primary_cat !== 'all') {
            $query->where('primary_category', $request->primary_cat);
        }
        if ($request->filled('sub_cat') && $request->sub_cat !== 'all') {
            $query->where('secondary_category', $request->sub_cat);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'pending_extraction') {
                $query->whereRaw("news_item_id IN (SELECT id FROM news_items WHERE status = 'pending_extraction')");
            }
        }

        $query->orderBy('published_at', 'desc');

        $perPage = (int) $request->get('per_page', 15);
        $items = $query->paginate($perPage);

        return response()->json($items);
    }

    public function store(NewsItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] = $data['status'] ?? 'pending_extraction';
        $data['is_active'] = false;
        $item = NewsItem::create($data);
        return response()->json($item, 201);
    }

    public function show(int $id): JsonResponse
    {
        $feedItem = FeedReadyItem::find($id);
        if ($feedItem) {
            $newsItem = NewsItem::find($feedItem->news_item_id);
            if ($newsItem) {
                return response()->json(array_merge($feedItem->toArray(), [
                    'click_count' => $newsItem->click_count ?? 0,
                    'full_news' => $newsItem,
                ]));
            }
            return response()->json($feedItem);
        }
        $newsItem = NewsItem::find($id);
        if (!$newsItem) abort(404);
        return response()->json($newsItem);
    }

    public function update(NewsItemRequest $request, int $id): JsonResponse
    {
        $feedItem = FeedReadyItem::find($id);
        $newsItem = $feedItem ? NewsItem::find($feedItem->news_item_id) : NewsItem::find($id);
        if (!$newsItem) abort(404);

        $data = $request->validated();
        $data['status'] = $data['status'] ?? $newsItem->status ?? 'pending_extraction';
        $newsItem->update($data);

        if (isset($data['relevance_mode']) && $feedItem) {
            $feedItem->update(['relevance_mode' => $data['relevance_mode']]);
        }

        return response()->json($newsItem);
    }

    public function destroy(int $id): JsonResponse
    {
        $feedItem = FeedReadyItem::find($id);
        if ($feedItem) {
            $newsItem = NewsItem::find($feedItem->news_item_id);
            if ($newsItem) $newsItem->delete();
        } else {
            $newsItem = NewsItem::find($id);
            if ($newsItem) $newsItem->delete();
        }
        return response()->json(['message' => 'Deleted'], 200);
    }
}
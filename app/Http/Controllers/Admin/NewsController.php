<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Http\Requests\NewsItemRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NewsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NewsItem::query();

        if ($request->filled('primary_cat') && $request->primary_cat !== 'all') {
            $query->where('primary_category', $request->primary_cat);
        }
        if ($request->filled('sub_cat') && $request->sub_cat !== 'all') {
            $query->where('secondary_category', $request->sub_cat);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $query->orderBy('published_at', 'desc');

        $perPage = (int) $request->get('per_page', 15);
        $items = $query->paginate($perPage);

        return response()->json($items);
    }

    public function store(NewsItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $item = NewsItem::create($data);
        return response()->json($item, 201);
    }

    public function show(int $id): JsonResponse
    {
        $item = NewsItem::findOrFail($id);
        return response()->json($item);
    }

    public function update(NewsItemRequest $request, int $id): JsonResponse
    {
        $item = NewsItem::findOrFail($id);
        $data = $request->validated();
        $item->update($data);
        return response()->json($item);
    }

    public function destroy(int $id): JsonResponse
    {
        $item = NewsItem::findOrFail($id);
        $item->delete();
        return response()->json(['message' => 'Deleted'], 200);
    }
}

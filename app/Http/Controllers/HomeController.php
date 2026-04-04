<?php

namespace App\Http\Controllers;

use App\Models\FeedReadyItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class HomeController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        $items = FeedReadyItem::where('is_active', true)
            ->orderBy('published_at', 'desc')
            ->limit(20)
            ->get(['id', 'title', 'summary', 'source', 'published_at', 'primary_category', 'secondary_category', 'url', 'location_label', 'lat', 'lng']);

        $stories = $items->map(function ($item) {
            $hoursAgo = $item->published_at
                ? (int) round(Carbon::now()->diffInMinutes(Carbon::parse($item->published_at)) / 60)
                : 0;

            return [
                'id'                => $item->id,
                'title'             => $item->title,
                'summary'           => $item->summary,
                'source'             => $item->source,
                'published_at'      => $item->published_at?->toIso8601String(),
                'primary_category'  => $item->primary_category,
                'secondary_category'=> $item->secondary_category,
                'url'               => $item->url,
                'location_label'    => $item->location_label,
                'hoursAgo'          => $hoursAgo,
                'type'              => 'interest', // default; JS will override for nearby/broader
                'category'          => $item->primary_category,
                'interestTag'       => $item->secondary_category,
                'locationName'      => $item->location_label,
                'distance_km'       => null,
            ];
        })->toArray();

        $categories = FeedReadyItem::where('is_active', true)
            ->distinct()
            ->pluck('primary_category')
            ->filter()
            ->sort()
            ->values()
            ->toArray();

        return view('home', [
            'initialStories' => $stories,
            'categories'     => $categories,
        ]);
    }
}

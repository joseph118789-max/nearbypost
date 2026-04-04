<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Validation\ValidationException;

class SubscriberPreferencesController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $sub = Subscriber::findOrFail($id);
        return response()->json(['data' => $sub->getPreferences()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sub = Subscriber::findOrFail($id);

        $validated = $request->validate([
            'preferred_categories'   => 'string|max:500|nullable',
            'location_lat'          => 'numeric|between:-90,90|nullable',
            'location_lng'         => 'numeric|between:-180,180|nullable',
            'max_distance_km'      => 'integer|min:1|max:500|nullable',
            'notification_frequency'=> 'string|in:immediate,daily,weekly,disabled|nullable',
        ]);

        $sub->updatePreferences($validated);

        return response()->json([
            'message' => 'Preferences updated',
            'data'    => $sub->getPreferences(),
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'updates' => 'required|array|min:1|max:100',
            'updates.*.id'                  => 'required|integer|exists:subscribers,id',
            'updates.*.preferred_categories' => 'string|max:500|nullable',
            'updates.*.location_lat'        => 'numeric|between:-90,90|nullable',
            'updates.*.location_lng'        => 'numeric|between:-180,180|nullable',
            'updates.*.max_distance_km'     => 'integer|min:1|max:500|nullable',
            'updates.*.notification_frequency'=> 'string|in:immediate,daily,weekly,disabled|nullable',
        ]);

        $updated = 0;
        foreach ($validated['updates'] as $u) {
            $sub = Subscriber::find($u['id']);
            if ($sub) {
                $sub->updatePreferences($u);
                $updated++;
            }
        }

        return response()->json([
            'message'     => 'Bulk update complete',
            'updated'     => $updated,
        ]);
    }
}

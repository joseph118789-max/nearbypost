<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Subscriber;

class SubscriberController extends Controller
{
    public function index(): JsonResponse
    {
        $subscribers = Subscriber::with('group')->paginate(20);
        return response()->json(['success' => true, 'data' => $subscribers]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|max:20|unique:subscribers,phone',
            'status'   => 'sometimes|in:active,inactive,blocked',
            'group_id' => 'sometimes|nullable|integer|exists:broadcast_groups,id',
        ]);

        $subscriber = Subscriber::create($validated);
        $subscriber->load('group');

        return response()->json(['success' => true, 'data' => $subscriber], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $subscriber = Subscriber::find($id);
        if (!$subscriber) {
            return response()->json(['success' => false, 'message' => 'Subscriber not found'], 404);
        }

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'phone'    => 'sometimes|string|max:20|unique:subscribers,phone,' . $id,
            'status'   => 'sometimes|in:active,inactive,blocked',
            'group_id' => 'sometimes|nullable|integer|exists:broadcast_groups,id',
        ]);

        $subscriber->update($validated);
        $subscriber->load('group');

        return response()->json(['success' => true, 'data' => $subscriber]);
    }

    public function destroy($id): JsonResponse
    {
        $subscriber = Subscriber::find($id);
        if (!$subscriber) {
            return response()->json(['success' => false, 'message' => 'Subscriber not found'], 404);
        }

        $subscriber->forceDelete();

        return response()->json(['success' => true, 'data' => $subscriber]);
    }
}

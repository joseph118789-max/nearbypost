<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\BroadcastGroup;

class BroadcastGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = BroadcastGroup::paginate(20);
        return response()->json(['success' => true, 'data' => $groups]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'is_active'   => 'sometimes|boolean',
        ]);

        if (!isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        $group = BroadcastGroup::create($validated);

        return response()->json(['success' => true, 'data' => $group], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $group = BroadcastGroup::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Broadcast group not found'], 404);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'is_active'   => 'sometimes|boolean',
        ]);

        $group->update($validated);

        return response()->json(['success' => true, 'data' => $group]);
    }

    public function destroy($id): JsonResponse
    {
        $group = BroadcastGroup::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Broadcast group not found'], 404);
        }

        $group->forceDelete();

        return response()->json(['success' => true, 'data' => $group]);
    }
}

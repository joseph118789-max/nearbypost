<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BroadcastGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BroadcastGroupController extends Controller
{
    /**
     * GET /api/admin/broadcast-groups
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = BroadcastGroup::withCount('subscribers');
            
            // Optional filters
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
            
            $groups = $query->orderBy('created_at', 'desc')->get()
                ->map(fn($g) => array_merge($g->toFrontendArray(), ['subscribers_count' => $g->subscribers_count]));
            
            Log::info('Broadcast groups list fetched', ['count' => $groups->count()]);
            
            return response()->json([
                'success' => true,
                'data' => $groups,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch broadcast groups', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch broadcast groups',
            ], 500);
        }
    }

    /**
     * POST /api/admin/broadcast-groups
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:broadcast_groups,name',
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);
            
            $validated['is_active'] = $validated['is_active'] ?? true;
            
            $group = BroadcastGroup::create($validated);
            
            Log::info('Broadcast group created', ['id' => $group->id, 'name' => $group->name]);
            
            return response()->json([
                'success' => true,
                'message' => 'Broadcast group created',
                'data' => $group->toFrontendArray(),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Broadcast group validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create broadcast group', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not create broadcast group',
            ], 500);
        }
    }

    /**
     * PUT /api/admin/broadcast-groups/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $group = BroadcastGroup::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255|unique:broadcast_groups,name,' . $id,
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);
            
            $group->update($validated);
            
            Log::info('Broadcast group updated', ['id' => $group->id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Broadcast group updated',
                'data' => $group->toFrontendArray(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Broadcast group not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Broadcast group update validation failed', ['id' => $id, 'errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update broadcast group', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not update broadcast group',
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/broadcast-groups/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $group = BroadcastGroup::findOrFail($id);
            
            // Check if group has subscribers - soft constraint
            $subscriberCount = $group->subscribers()->count();
            if ($subscriberCount > 0) {
                Log::warning('Broadcast group has subscribers', ['id' => $id, 'count' => $subscriberCount]);
                // Still allow deletion but log it - subscribers will have group_id set to null
            }
            
            $group->delete();
            
            Log::info('Broadcast group deleted', ['id' => $id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Broadcast group deleted',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Broadcast group not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to delete broadcast group', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not delete broadcast group',
            ], 500);
        }
    }
}
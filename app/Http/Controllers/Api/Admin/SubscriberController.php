<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriberController extends Controller
{
    /**
     * GET /api/admin/subscribers
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Subscriber::with('group');
            
            // Optional filters
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }
            if ($request->has('group_id')) {
                $query->where('group_id', $request->group_id);
            }
            
            $subscribers = $query->orderBy('created_at', 'desc')->get()
                ->map(fn($s) => $s->toFrontendArray());
            
            Log::info('Subscribers list fetched', ['count' => $subscribers->count()]);
            
            return response()->json([
                'success' => true,
                'data' => $subscribers,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch subscribers', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch subscribers',
            ], 500);
        }
    }

    /**
     * POST /api/admin/subscribers
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'required|string|unique:subscribers,phone|max:20',
                'status' => 'nullable|in:active,inactive,unsubscribed',
                'group_id' => 'nullable|exists:broadcast_groups,id',
            ]);
            
            $validated['status'] = $validated['status'] ?? 'active';
            
            $subscriber = Subscriber::create($validated);
            $subscriber->load('group');
            
            Log::info('Subscriber created', ['id' => $subscriber->id, 'phone' => $subscriber->phone]);
            
            return response()->json([
                'success' => true,
                'message' => 'Subscriber created',
                'data' => $subscriber->toFrontendArray(),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Subscriber validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create subscriber', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not create subscriber',
            ], 500);
        }
    }

    /**
     * PUT /api/admin/subscribers/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $subscriber = Subscriber::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|unique:subscribers,phone,' . $id . '|max:20',
                'status' => 'sometimes|in:active,inactive,unsubscribed',
                'group_id' => 'nullable|exists:broadcast_groups,id',
            ]);
            
            $subscriber->update($validated);
            $subscriber->load('group');
            
            Log::info('Subscriber updated', ['id' => $subscriber->id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Subscriber updated',
                'data' => $subscriber->toFrontendArray(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Subscriber not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Subscriber update validation failed', ['id' => $id, 'errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update subscriber', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not update subscriber',
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/subscribers/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $subscriber = Subscriber::findOrFail($id);
            $subscriber->delete();
            
            Log::info('Subscriber deleted', ['id' => $id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Subscriber deleted',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Subscriber not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to delete subscriber', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not delete subscriber',
            ], 500);
        }
    }

    // ========== Preference Management ==========

    /**
     * GET /api/admin/subscribers/{id}/preferences
     */
    public function getPreferences(int $id): JsonResponse
    {
        try {
            $subscriber = Subscriber::findOrFail($id);
            
            Log::info('Preferences fetched', ['subscriber_id' => $id]);
            
            return response()->json([
                'success' => true,
                'data' => $subscriber->getPreferences(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Subscriber not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch preferences', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not fetch preferences',
            ], 500);
        }
    }

    /**
     * PUT /api/admin/subscribers/{id}/preferences
     * Partial update with merge logic
     */
    public function updatePreferences(Request $request, int $id): JsonResponse
    {
        try {
            $subscriber = Subscriber::findOrFail($id);
            
            $validated = $request->validate([
                'preferred_categories' => 'nullable|array',
                'preferred_categories.*' => 'string|max:100',
                'alert_radius_km' => 'nullable|numeric|min:0.01|max:1000',
                'location_lat' => 'nullable|numeric|between:-90,90',
                'location_lng' => 'nullable|numeric|between:-180,180',
                'notification_frequency' => 'nullable|string|in:' . implode(',', Subscriber::VALID_FREQUENCIES),
            ]);
            
            $subscriber->updatePreferences($validated);
            $subscriber->load('group');
            
            Log::info('Preferences updated', ['subscriber_id' => $id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Preferences updated',
                'data' => $subscriber->getPreferences(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Subscriber not found',
            ], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Preferences validation failed', ['id' => $id, 'errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update preferences', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not update preferences',
            ], 500);
        }
    }

    /**
     * POST /api/admin/subscribers/bulk-update-preferences
     * Bulk preference merge for multiple subscribers
     */
    public function bulkUpdatePreferences(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'subscriber_ids' => 'required|array|min:1',
                'subscriber_ids.*' => 'integer|exists:subscribers,id',
                'preferences' => 'required|array|min:1',
                'preferences.preferred_categories' => 'nullable|array',
                'preferences.preferred_categories.*' => 'string|max:100',
                'preferences.alert_radius_km' => 'nullable|numeric|min:0.01|max:1000',
                'preferences.location_lat' => 'nullable|numeric|between:-90,90',
                'preferences.location_lng' => 'nullable|numeric|between:-180,180',
                'preferences.notification_frequency' => 'nullable|string|in:' . implode(',', Subscriber::VALID_FREQUENCIES),
            ]);
            
            $subscriberIds = $validated['subscriber_ids'];
            $preferences = $validated['preferences'];
            
            $updated = 0;
            $failed = 0;
            
            foreach ($subscriberIds as $id) {
                try {
                    $subscriber = Subscriber::findOrFail($id);
                    $subscriber->updatePreferences($preferences);
                    $updated++;
                } catch (\Exception $e) {
                    $failed++;
                    Log::warning('Failed to update subscriber preferences', ['id' => $id, 'error' => $e->getMessage()]);
                }
            }
            
            Log::info('Bulk preferences updated', ['updated' => $updated, 'failed' => $failed]);
            
            return response()->json([
                'success' => true,
                'message' => "Updated {$updated} subscribers" . ($failed > 0 ? ", {$failed} failed" : ''),
                'data' => [
                    'updated' => $updated,
                    'failed' => $failed,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Bulk preferences validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to bulk update preferences', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not bulk update preferences',
            ], 500);
        }
    }
}
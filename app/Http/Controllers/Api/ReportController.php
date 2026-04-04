<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    /**
     * POST /api/report-content
     */
    public function store(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        
        try {
            $validated = $request->validate([
                'news_item_id' => 'nullable|integer|exists:news_items,id',
                'reason' => 'required|string|in:spam,inaccurate,inappropriate,other',
                'note' => 'nullable|string|max:2000',
            ]);
            
            // Capture trace info
            $validated['ip_address'] = $request->ip();
            $validated['user_agent'] = $request->userAgent();
            $validated['status'] = 'pending';
            
            $report = Report::create($validated);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::info('Report submitted successfully', [
                'id' => $report->id,
                'news_item_id' => $report->news_item_id,
                'reason' => $report->reason,
                'duration_ms' => $duration,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Report submitted',
                'data' => $report->toFrontendArray(),
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::warning('Report validation failed', [
                'errors' => $e->errors(),
                'duration_ms' => $duration,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::error('Report submission failed', [
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Could not submit report',
            ], 500);
        }
    }
}
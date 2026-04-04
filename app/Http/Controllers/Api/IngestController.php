<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\RawIngest;
use App\Models\FailedIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class IngestController extends Controller
{
    /**
     * POST /api/internal/ingest/news
     * 
     * Receives normalized news from n8n
     * Input: title, url, source, published_at, summary, category
     */
    public function ingest(Request $request): JsonResponse
    {
        $requestId = 'ingest_' . uniqid();
        $startTime = microtime(true);
        
        Log::info('ingest.start', [
            'request_id' => $requestId,
            'source' => $request->input('source'),
            'url' => $request->input('url'),
        ]);
        
        try {
            // Validate required fields
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:500',
                'url' => 'required|url|max:1000',
                'source' => 'required|string|max:100',
                'published_at' => 'nullable|date',
                'summary' => 'nullable|string',
                'category' => 'nullable|string|max:100',
            ]);
            
            if ($validator->fails()) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                Log::warning('ingest.validation_failed', [
                    'request_id' => $requestId,
                    'errors' => $validator->errors()->toArray(),
                    'duration_ms' => $duration,
                ]);
                
                // Store failed payload in dead-letter bucket
                $this->storeFailedIngest($request->all(), 'validation_failed: ' . json_encode($validator->errors()->toArray()), $requestId);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $data = $validator->validated();
            
            // Check for duplicate by URL
            $existing = NewsItem::where('url', $data['url'])->first();
            if ($existing) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                Log::info('ingest.duplicate', [
                    'request_id' => $requestId,
                    'existing_id' => $existing->id,
                    'duration_ms' => $duration,
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Duplicate, already exists',
                    'existing_id' => $existing->id,
                ], 200);
            }
            
            // Store raw incoming payload for traceability
            $rawIngest = RawIngest::create([
                'source' => $data['source'],
                'raw_json_payload' => $data,
                'received_at' => now(),
                'processing_status' => 'pending',
            ]);
            
            // Create news item
            $newsItem = NewsItem::create([
                'title' => $data['title'],
                'url' => $data['url'],
                'source' => $data['source'],
                'published_at' => $data['published_at'] ?? null,
                'summary' => $data['summary'] ?? null,
                'primary_category' => $data['category'] ?? 'others',
                'status' => 'pending_extraction',
            ]);
            
            // Update raw ingest status
            $rawIngest->update(['processing_status' => 'processed']);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('ingest.success', [
                'request_id' => $requestId,
                'news_item_id' => $newsItem->id,
                'source' => $data['source'],
                'duration_ms' => $duration,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'News item ingested',
                'data' => [
                    'id' => $newsItem->id,
                    'title' => $newsItem->title,
                ],
            ], 201);
            
        } catch (\Illuminate\Database\QueryException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error('ingest.database_error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            
            $this->storeFailedIngest($request->all(), 'database_error: ' . $e->getMessage(), $requestId);
            
            return response()->json([
                'success' => false,
                'message' => 'Database error during ingestion',
            ], 500);
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error('ingest.unexpected_error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            
            $this->storeFailedIngest($request->all(), 'unexpected_error: ' . $e->getMessage(), $requestId);
            
            return response()->json([
                'success' => false,
                'message' => 'Could not ingest news item',
            ], 500);
        }
    }
    
    /**
     * Store failed ingestion in dead-letter bucket
     */
    private function storeFailedIngest(array $payload, string $reason, string $requestId): void
    {
        try {
            FailedIngest::create([
                'source' => $payload['source'] ?? 'unknown',
                'url' => $payload['url'] ?? null,
                'title' => $payload['title'] ?? null,
                'raw_payload' => $payload,
                'failure_reason' => $reason,
                'retry_count' => 0,
                'failed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store failed ingest record', ['error' => $e->getMessage()]);
        }
    }
}
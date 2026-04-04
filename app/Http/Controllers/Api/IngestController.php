<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\RawIngest;
use App\Models\FailedIngestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class IngestController extends Controller
{
    public function ingestNews(Request $request)
    {
        $start = microtime(true);
        $timestamp = now()->toIso8601String();

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:500',
            'url' => 'required|url|max:2048',
            'source' => 'required|string|max:255',
            'published_at' => 'required|date',
            'summary' => 'nullable|string',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            $failureReason = 'validation_error';
            $errorMessage = json_encode($validator->errors()->toArray());

            RawIngest::create([
                'source' => $request->input('source', 'unknown'),
                'raw_json_payload' => $request->all(),
                'received_at' => now(),
                'processing_status' => 'failed',
                'error_message' => $errorMessage,
                'news_item_id' => null,
            ]);

            FailedIngestion::create([
                'source' => $request->input('source', 'unknown'),
                'url' => $request->input('url'),
                'title' => $request->input('title'),
                'raw_payload' => $request->all(),
                'failure_reason' => $failureReason,
                'retry_count' => 0,
                'failed_at' => now(),
            ]);

            Log::info('Ingest event', [
                'event_name' => 'news_ingest',
                'source_name' => $request->input('source', 'unknown'),
                'url' => $request->input('url'),
                'outcome' => 'failed',
                'failure_reason' => $failureReason,
                'timestamp' => $timestamp,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'failure_reason' => $failureReason,
            ], 422);
        }

        try {
            $rawIngest = RawIngest::create([
                'source' => $request->source,
                'raw_json_payload' => $request->all(),
                'received_at' => now(),
                'processing_status' => 'pending',
                'error_message' => null,
                'news_item_id' => null,
            ]);

            $existing = NewsItem::where('url', $request->url)->first();
            if ($existing) {
                $rawIngest->update([
                    'processing_status' => 'processed',
                    'news_item_id' => $existing->id,
                ]);

                Log::info('Ingest event', [
                    'event_name' => 'news_ingest',
                    'source_name' => $request->source,
                    'url' => $request->url,
                    'outcome' => 'duplicate',
                    'failure_reason' => null,
                    'timestamp' => $timestamp,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already ingested',
                    'duplicate' => true,
                    'id' => $existing->id,
                ], 200);
            }

            $newsItem = NewsItem::create([
                'title' => $request->title,
                'url' => $request->url,
                'source' => $request->source,
                'summary' => $request->summary ?? null,
                'published_at' => $request->published_at,
                'primary_category' => $request->category ?? 'general',
                'status' => 'pending',
            ]);

            $rawIngest->update([
                'processing_status' => 'processed',
                'news_item_id' => $newsItem->id,
            ]);

            Log::info('Ingest event', [
                'event_name' => 'news_ingest',
                'source_name' => $newsItem->source,
                'url' => $newsItem->url,
                'outcome' => 'created',
                'failure_reason' => null,
                'timestamp' => $timestamp,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ingested',
                'id' => $newsItem->id,
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            $failureReason = 'db_error';

            try {
                if (isset($rawIngest)) {
                    $rawIngest->update([
                        'processing_status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e2) {}

            FailedIngestion::create([
                'source' => $request->source,
                'url' => $request->url,
                'title' => $request->title,
                'raw_payload' => $request->all(),
                'failure_reason' => $failureReason,
                'retry_count' => 0,
                'failed_at' => now(),
            ]);

            Log::info('Ingest event', [
                'event_name' => 'news_ingest',
                'source_name' => $request->source,
                'url' => $request->url,
                'outcome' => 'failed',
                'failure_reason' => $failureReason,
                'timestamp' => $timestamp,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not ingest item',
                'failure_reason' => $failureReason,
            ], 500);
        } catch (\Exception $e) {
            $failureReason = 'unknown_error';

            try {
                if (isset($rawIngest)) {
                    $rawIngest->update([
                        'processing_status' => 'failed',
                        'error_message' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e2) {}

            FailedIngestion::create([
                'source' => $request->source,
                'url' => $request->url,
                'title' => $request->title,
                'raw_payload' => $request->all(),
                'failure_reason' => $failureReason,
                'retry_count' => 0,
                'failed_at' => now(),
            ]);

            Log::info('Ingest event', [
                'event_name' => 'news_ingest',
                'source_name' => $request->source,
                'url' => $request->url,
                'outcome' => 'failed',
                'failure_reason' => $failureReason,
                'timestamp' => $timestamp,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not ingest item',
                'failure_reason' => $failureReason,
            ], 500);
        }
    }
}
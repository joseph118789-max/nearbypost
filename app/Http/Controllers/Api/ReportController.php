<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'news_item_id' => 'nullable|exists:news_items,id',
                'reason' => 'required|string|max:255',
                'note' => 'nullable|string',
            ]);

            $report = Report::create([
                ...$validated,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Log::info('Report submitted', [
                'report_id' => $report->id,
                'news_item_id' => $report->news_item_id,
                'reason' => $report->reason,
                'ip' => $report->ip_address,
            ]);

            return response()->json(['success' => true, 'message' => 'Report submitted'], 201);
        } catch (ValidationException $e) {
            Log::warning('Report validation failed', [
                'errors' => $e->errors(),
                'ip' => $request->ip(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Report submission error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            throw $e;
        }
    }
}

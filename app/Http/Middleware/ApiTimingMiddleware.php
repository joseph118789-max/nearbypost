<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiTimingMiddleware
{
    /**
     * Handle an incoming request.
     * Adds lightweight timing logs for key endpoints.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        // Track timing for key endpoints
        $trackedPaths = [
            '/api/feed',
            '/api/feed/',
            '/api/report-content',
            '/api/internal/ingest',
        ];
        
        $shouldTrack = collect($trackedPaths)->some(fn($path) => $request->is($path));
        
        $response = $next($request);
        
        if ($shouldTrack) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('api.timing', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
            ]);
        }
        
        return $response;
    }
}
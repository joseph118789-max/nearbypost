<?php

namespace App\Http\Middleware;

use App\Services\ApiAnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class ApiAnalyticsMiddleware
{
    private ApiAnalyticsService $analytics;

    public function __construct(ApiAnalyticsService $analytics)
    {
        $this->analytics = $analytics;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $startMs = hrtime(true);

        // Extract API key from Authorization header
        $apiKey = $request->bearerToken() ?? $request->header('X-API-Key', 'anonymous');
        $endpoint = $request->routeIs('api.*') ? $request->path() : $request->path();

        // Check first. Running this after $next paid the full cost of every
        // request it then rejected, and returned 429 for writes that had
        // already been applied.
        $limitResult = $this->analytics->checkRateLimit($apiKey, '/' . $endpoint);

        if (!$limitResult['allowed']) {
            return response()->json([
                'error'   => 'rate_limit_exceeded',
                'message' => $limitResult['reason'],
            ], 429);
        }

        $response = $next($request);

        $elapsedMs = (int) ((hrtime(true) - $startMs) / 1_000_000);

        // Log the request
        try {
            $itemsReturned = is_array($response->getOriginalContent())
                ? count($response->getOriginalContent())
                : 0;

            $this->analytics->logAndCheck(
                $apiKey,
                '/' . $endpoint,
                $request,
                $response->getStatusCode(),
                $elapsedMs,
                $itemsReturned
            );
        } catch (\Exception $e) {
            Log::error('Analytics middleware error', ['error' => $e->getMessage()]);
        }

        // Add timing header
        $response->headers->set('X-Response-Time-Ms', (string) $elapsedMs);

        return $response;
    }
}

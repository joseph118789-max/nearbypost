<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class LogApiTiming
{
    public function handle($request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = round((microtime(true) - $start) * 1000, 2);

        Log::info('API timing', [
            'method' => $request->method(),
            'uri' => $request->path(),
            'duration_ms' => $duration,
            'status' => $response->getStatusCode(),
        ]);

        $response->headers->set('X-Response-Time', $duration . 'ms');
        return $response;
    }
}

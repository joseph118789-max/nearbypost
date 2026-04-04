<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ApiAnalyticsService
{
    private const RATE_LIMIT_PER_MINUTE = 60;

    public function logAndCheck(string $apiKey, string $endpoint, Request $request, int $responseCode, int $responseTimeMs, int $itemsReturned = 0): array
    {
        $limitResult = $this->checkRateLimit($apiKey, $endpoint);
        if (!$limitResult['allowed']) {
            return $limitResult;
        }

        try {
            DB::table('api_usage_analytics')->insert([
                'api_key'          => $apiKey ?: 'anonymous',
                'endpoint'         => $endpoint,
                'method'           => $request->method(),
                'response_code'    => $responseCode,
                'response_time_ms' => $responseTimeMs,
                'items_returned'   => $itemsReturned,
                'caller_ip'        => $request->ip(),
                'user_agent'       => mb_substr($request->userAgent() ?? '', 0, 500),
                'requested_at'     => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Analytics write failed', ['error' => $e->getMessage()]);
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function checkRateLimit(string $apiKey, string $endpoint): array
    {
        $now = now();
        $minuteKey = (int) $now->format('i');
        $windowStart = $now->copy()->subMinutes(1)->startOfMinute();
        $windowEnd   = $now->copy()->startOfMinute()->addMinutes(1);

        // Count requests in sliding 2-minute window
        $count = DB::table('api_rate_limits')
            ->where('api_key', $apiKey ?: 'anonymous')
            ->where('endpoint', $endpoint)
            ->where('window_start_minute', '>=', (int) $windowStart->format('i'))
            ->where('window_start_minute', '<', (int) $windowEnd->format('i'))
            ->sum('request_count');

        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            return [
                'allowed' => false,
                'reason'  => 'Rate limit exceeded. Max ' . self::RATE_LIMIT_PER_MINUTE . ' req/min.',
            ];
        }

        // Increment or insert
        try {
            $existing = DB::table('api_rate_limits')
                ->where('api_key', $apiKey ?: 'anonymous')
                ->where('endpoint', $endpoint)
                ->where('window_start_minute', $minuteKey)
                ->first();

            if ($existing) {
                DB::table('api_rate_limits')
                    ->where('id', $existing->id)
                    ->update([
                        'request_count' => $existing->request_count + 1,
                        'updated_at'    => now(),
                    ]);
            } else {
                DB::table('api_rate_limits')->insert([
                    'api_key'           => $apiKey ?: 'anonymous',
                    'endpoint'          => $endpoint,
                    'window_start_minute'=> $minuteKey,
                    'request_count'    => 1,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Rate limit counter failed', ['error' => $e->getMessage()]);
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function getUsage(string $apiKey, int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $total = DB::table('api_usage_analytics')
            ->where('api_key', $apiKey)
            ->where('requested_at', '>=', $since)
            ->count();

        $avgTime = DB::table('api_usage_analytics')
            ->where('api_key', $apiKey)
            ->where('requested_at', '>=', $since)
            ->avg('response_time_ms');

        return [
            'api_key'        => $apiKey,
            'requests_24h'  => $total,
            'avg_latency_ms'=> round($avgTime ?? 0, 1),
            'limit_per_min' => self::RATE_LIMIT_PER_MINUTE,
        ];
    }
}

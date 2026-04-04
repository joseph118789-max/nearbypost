<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApiUsageAnalytics extends Command
{
    protected $signature = 'analytics:api-usage {--period=day : Time period (hour, day, week)}';
    protected $description = 'Show API usage analytics';

    public function handle(): int
    {
        $period = $this->option('period');
        $hours = match($period) {
            'hour' => 1,
            'day' => 24,
            'week' => 168,
            default => 24,
        };

        $since = now()->subHours($hours);

        $this->info("API Usage Analytics - Last {$period}");
        $this->line('');

        // Top API keys by request count
        $topKeys = DB::table('api_usage_analytics')
            ->select('api_key', DB::raw('COUNT(*) as total_requests'), DB::raw('AVG(response_time_ms) as avg_latency'))
            ->where('requested_at', '>=', $since)
            ->groupBy('api_key')
            ->orderByDesc('total_requests')
            ->limit(10)
            ->get();

        $this->table(
            ['API Key', 'Requests', 'Avg Latency (ms)'],
            $topKeys->map(fn($r) => [$r->api_key, $r->total_requests, round($r->avg_latency, 1)])
        );
        $this->line('');

        // Top endpoints
        $topEndpoints = DB::table('api_usage_analytics')
            ->select('endpoint', DB::raw('COUNT(*) as hits'))
            ->where('requested_at', '>=', $since)
            ->groupBy('endpoint')
            ->orderByDesc('hits')
            ->limit(10)
            ->get();

        $this->table(
            ['Endpoint', 'Hits'],
            $topEndpoints->map(fn($r) => [$r->endpoint, $r->hits])
        );
        $this->line('');

        // Response codes
        $codes = DB::table('api_usage_analytics')
            ->select('response_code', DB::raw('COUNT(*) as count'))
            ->where('requested_at', '>=', $since)
            ->groupBy('response_code')
            ->orderByDesc('count')
            ->get();

        $this->table(
            ['Status Code', 'Count'],
            $codes->map(fn($r) => [$r->response_code, $r->count])
        );

        return Command::SUCCESS;
    }
}

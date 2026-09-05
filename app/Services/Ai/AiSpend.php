<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What the model costs, and how much is left.
 *
 * ⚠ THE PRICES BELOW ARE AN ASSUMPTION. They are DeepSeek's published rates as
 * read on 2026-09-01, and they will go out of date without telling us. Every
 * figure derived from them is labelled an estimate in the panel for that reason.
 *
 * The balance is not an assumption: it is what the provider says is left, and
 * the difference between two readings is what was actually spent. Where the two
 * disagree, believe the balance - and the size of the disagreement is worth
 * looking at, because it usually means the price list moved.
 */
class AiSpend
{
    /** Per million tokens, USD. https://api-docs.deepseek.com/quick_start/pricing */
    private const RATES = [
        'deepseek-chat' => [
            'cache_hit'  => 0.07,
            'cache_miss' => 0.27,
            'output'     => 1.10,
        ],
        'deepseek-reasoner' => [
            'cache_hit'  => 0.14,
            'cache_miss' => 0.55,
            'output'     => 2.19,
        ],
    ];

    public const RATES_READ_ON = '2026-09-01';

    /**
     * What one call cost, in USD.
     *
     * Cached input is billed at roughly a tenth of fresh input, so a total
     * token count says almost nothing on its own - since the prompt was
     * reordered, most input is cached, and treating it as fresh would overstate
     * the bill several times over.
     */
    public function costOf(int $cacheHit, int $cacheMiss, int $output, string $model = 'deepseek-chat'): float
    {
        $rates = self::RATES[$model] ?? self::RATES['deepseek-chat'];

        return round(
            ($cacheHit / 1_000_000) * $rates['cache_hit']
            + ($cacheMiss / 1_000_000) * $rates['cache_miss']
            + ($output / 1_000_000) * $rates['output'],
            6
        );
    }

    /**
     * What the provider says is left. Cached briefly: it is shown on a page
     * that may be refreshed, and it changes on the timescale of hours.
     *
     * @return array{balance: ?float, currency: string, checked_at: ?string, error: ?string}
     */
    public function balance(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('ai:balance');
        }

        return Cache::remember('ai:balance', 600, function () {
            $key = (string) config('services.deepseek.key');

            if (trim($key) === '') {
                return ['balance' => null, 'currency' => 'USD', 'checked_at' => null,
                        'error' => 'No API key is configured.'];
            }

            try {
                $response = Http::withToken($key)->timeout(20)->get('https://api.deepseek.com/user/balance');

                if (!$response->successful()) {
                    return ['balance' => null, 'currency' => 'USD', 'checked_at' => null,
                            'error' => 'DeepSeek answered ' . $response->status() . '.'];
                }

                $info = $response->json('balance_infos.0', []);

                return [
                    'balance'    => isset($info['total_balance']) ? (float) $info['total_balance'] : null,
                    'currency'   => $info['currency'] ?? 'USD',
                    'checked_at' => now()->toDateTimeString(),
                    'error'      => null,
                ];
            } catch (\Throwable $e) {
                Log::warning('Balance check failed', ['error' => $e->getMessage()]);

                return ['balance' => null, 'currency' => 'USD', 'checked_at' => null,
                        'error' => 'Could not reach DeepSeek.'];
            }
        });
    }

    /** Write today's reading, so tomorrow's difference is a real spend figure. */
    public function record(): ?float
    {
        $balance = $this->balance(true);

        if ($balance['balance'] === null) {
            return null;
        }

        DB::table('ai_balance_log')->insert([
            'provider'    => 'deepseek',
            'balance'     => $balance['balance'],
            'currency'    => $balance['currency'],
            'recorded_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $balance['balance'];
    }

    /**
     * Spend per day: what the tokens say, and what the balance says.
     *
     * @return list<array{day: string, calls: int, estimated: float, from_balance: ?float, cached_pct: ?int}>
     */
    public function daily(int $days = 14): array
    {
        // ⛔ KL, not UTC. The page above this says "Malaysia time" and this
        // grouped by the raw UTC date, so every call between 16:00 and 23:59
        // KL was counted against the previous day - eight hours of the busiest
        // part of the day, on the wrong row. The owner's basis is GMT+8.
        $estimated = DB::table('ai_processing_jobs')
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw("to_char(created_at + interval '8 hours', 'YYYY-MM-DD') as day,
                         count(*) as calls,
                         coalesce(sum(estimated_cost), 0) as estimated,
                         coalesce(sum(cache_hit_tokens), 0) as hit,
                         coalesce(sum(cache_miss_tokens), 0) as miss")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // The balance at the end of each day, so the drop between two days is
        // what was actually spent in between.
        $closing = DB::table('ai_balance_log')
            ->where('recorded_at', '>=', now()->subDays($days + 1)->startOfDay())
            // ⛔ DELIBERATELY NOT SHIFTED, unlike the token totals above.
            // ai:balance runs at 16:00 UTC *because* that is midnight in KL, so
            // a reading already sits at the close of a Malaysian day and its
            // UTC date is that day's date. Adding eight hours moves it into the
            // next day and every "actual" slides one row down - which it did,
            // for about a minute, until the numbers were read back.
            ->selectRaw("to_char(recorded_at, 'YYYY-MM-DD') as day, min(balance) as closing")
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('closing', 'day')
            ->all();

        $out = [];
        $previous = null;

        for ($i = $days - 1; $i >= 0; $i--) {
            // The day list must be KL too, or the newest row is missing for
            // the eight hours before UTC midnight.
            $day = now()->addHours(8)->subDays($i)->toDateString();
            $row = $estimated[$day] ?? null;
            $hit = (int) ($row->hit ?? 0);
            $miss = (int) ($row->miss ?? 0);

            $fromBalance = null;

            if ($previous !== null && isset($closing[$day])) {
                $drop = $previous - (float) $closing[$day];
                $fromBalance = $drop > 0 ? round($drop, 4) : 0.0;
            }

            $out[] = [
                'day'          => $day,
                'calls'        => (int) ($row->calls ?? 0),
                'estimated'    => round((float) ($row->estimated ?? 0), 4),
                'from_balance' => $fromBalance,
                'cached_pct'   => ($hit + $miss) > 0 ? (int) round($hit * 100 / ($hit + $miss)) : null,
            ];

            if (isset($closing[$day])) {
                $previous = (float) $closing[$day];
            }
        }

        return $out;
    }

    /**
     * How long the balance lasts at the recent rate.
     *
     * Deliberately based on the last seven days rather than yesterday: one
     * catch-up run is not a spending pattern, and a figure that swings between
     * three days and three months every morning is one nobody trusts.
     */
    public function daysLeft(?float $balance): ?int
    {
        if ($balance === null || $balance <= 0) {
            return null;
        }

        $recent = DB::table('ai_processing_jobs')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('count(*) as calls, coalesce(sum(estimated_cost), 0) as spent')
            ->first();

        // ⛔ Too few calls is not a spending rate. Straight after the archive was
        // purged this arithmetic produced "about 353,627 days", which is worse
        // than showing nothing: one absurd figure costs a reader their trust in
        // every other number on the page.
        if ((int) $recent->calls < 200) {
            return null;
        }

        $perDay = (float) $recent->spent / 7;

        return $perDay > 0.0001 ? (int) floor($balance / $perDay) : null;
    }
}

<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * How much money is left with a provider, so the admin knows when to top up.
 *
 * DeepSeek and Moonshot answer a balance call. OpenAI, Google and Anthropic
 * do not expose a balance, so for those the panel keeps an estimate: the
 * amount the admin says was topped up, minus what our own call log has spent
 * since. Either way the panel also says how many days that lasts at the last
 * week's rate.
 *
 * @return array{source: string, balance: ?float, currency: string, checked_at: ?string, spent_since: ?float, credit: ?float, credit_set_at: ?string, days_left: ?int, low: bool, error: ?string}
 */
final class ProviderBalance
{
    /** One wallet for every model that shares a key: live balance from the first model with a balance call, spending summed across all of them. */
    public static function forAccount(array $group, bool $fresh = false): array
    {
        $owner = $group[0];
        foreach ($group as $g) { if (!empty($g['balance_kind'])) { $owner = $g; break; } }
        $keys = array_column($group, 'key');
        $out = self::for($owner, $fresh, $keys);

        if ($out['balance'] === null) {
            foreach ($group as $g) {
                if (isset($g['credit_usd'])) { $out = self::for($g, $fresh, $keys); break; }
            }
        }

        return $out;
    }

    public static function for(array $p, bool $fresh = false, ?array $spendKeys = null): array
    {
        $spendKeys = $spendKeys ?: [$p['key']];
        $out = ['source' => 'none', 'balance' => null, 'currency' => 'USD', 'checked_at' => null, 'spent_since' => null,
                'credit' => isset($p['credit_usd']) ? (float) $p['credit_usd'] : null, 'credit_set_at' => $p['credit_set_at'] ?? null,
                'days_left' => null, 'low' => false, 'error' => null];

        $key = trim((string) (config('services.ai.keys.' . $p['key_name']) ?? ''));

        if (!empty($p['balance_kind']) && $key !== '') {
            $live = self::live($p, $key, $fresh);
            $out = array_merge($out, $live, ['source' => 'live']);
        }

        if ($out['balance'] === null && $out['credit'] !== null) {
            $since = $out['credit_set_at'] ? \Carbon\Carbon::parse($out['credit_set_at']) : now()->subYears(5);
            $spent = (float) DB::table('ai_call_log')->whereIn('provider_key', $spendKeys)->where('created_at', '>=', $since)->sum('cost');
            $out['spent_since'] = round($spent, 4);
            $out['balance'] = round($out['credit'] - $spent, 4);
            $out['source'] = 'estimate';
        }

        if ($out['balance'] !== null) {
            $out['days_left'] = self::daysLeft($spendKeys, (float) $out['balance']);
            $out['low'] = (float) $out['balance'] < (float) ($p['low_balance_usd'] ?? 3);
        }

        return $out;
    }

    private static function live(array $p, string $key, bool $fresh): array
    {
        $cacheKey = 'ai.balance.' . $p['key'];

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 600, function () use ($p, $key) {
            try {
                $base = rtrim((string) $p['base_url'], '/');

                if ($p['balance_kind'] === 'deepseek') {
                    $r = Http::withToken($key)->timeout(15)->get(preg_replace('#/v1$#', '', $base) . '/user/balance');
                    $info = $r->successful() ? ($r->json('balance_infos.0') ?? []) : [];

                    return ['balance' => isset($info['total_balance']) ? (float) $info['total_balance'] : null, 'currency' => $info['currency'] ?? 'USD',
                            'checked_at' => now()->toDateTimeString(), 'error' => $r->successful() ? null : 'DeepSeek answered ' . $r->status()];
                }

                if ($p['balance_kind'] === 'moonshot') {
                    $r = Http::withToken($key)->timeout(15)->get($base . '/users/me/balance');
                    $data = $r->successful() ? ($r->json('data') ?? []) : [];

                    return ['balance' => isset($data['available_balance']) ? (float) $data['available_balance'] : null, 'currency' => 'USD',
                            'checked_at' => now()->toDateTimeString(), 'error' => $r->successful() ? null : 'Moonshot answered ' . $r->status()];
                }
            } catch (\Throwable $e) {
                return ['balance' => null, 'currency' => 'USD', 'checked_at' => null, 'error' => 'Could not reach ' . $p['name']];
            }

            return ['balance' => null, 'currency' => 'USD', 'checked_at' => null, 'error' => null];
        });
    }

    /** Days the balance lasts at the last seven days' rate; nothing when too few calls to call it a rate. */
    private static function daysLeft(array $providerKeys, float $balance): ?int
    {
        if ($balance <= 0) {
            return 0;
        }

        $r = DB::table('ai_call_log')->whereIn('provider_key', $providerKeys)->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('count(*) as calls, coalesce(sum(cost), 0) as spent')->first();

        if ((int) $r->calls < 50) {
            return null;
        }

        $perDay = (float) $r->spent / 7;

        return $perDay > 0.0001 ? (int) floor($balance / $perDay) : null;
    }
}

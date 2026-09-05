<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AiRouter::for('enrich')->complete($prompt) - the task's primary model
 * answers; if it fails (down, 429, timeout, bad reply) the helper answers; when
 * the task is set to "share" and the backlog is long, calls alternate between
 * them. Every call is logged; a provider that fails three times in a row rests
 * for ten minutes. Set up in the panel at /admin/brain/ai (owner, 4 Sep 2026:
 * "options to use different AI for different task ... load balancing ... when
 * one AI is down").
 */
final class AiRouter
{
    public const COOLDOWN_MINUTES = 10;
    public const FAILURES_BEFORE_REST = 3;

    /**
     * @param string|null $country ISO2 of the publisher's country; the note written for that
     *                             country is added to the base note, as the playbook does.
     */
    public static function for(string $task, int $backlog = 0, ?string $country = null): AiTaskClient
    {
        return new AiTaskClient($task, $backlog, $country);
    }

    /** All providers, keyed, as arrays. Cached a minute: read on every call. */
    public static function providers(): array
    {
        return Cache::remember('ai.providers', 60, function () {
            if (!Schema::hasTable('ai_providers')) {
                return self::defaults()['providers'];
            }

            return DB::table('ai_providers')->orderBy('name')->get()->map(fn ($r) => (array) $r)->keyBy('key')->all();
        });
    }

    public static function tasks(): array
    {
        return Cache::remember('ai.tasks', 60, function () {
            if (!Schema::hasTable('ai_tasks')) {
                return self::defaults()['tasks'];
            }

            return DB::table('ai_tasks')->orderBy('sort_order')->get()->map(fn ($r) => (array) $r)->keyBy('key')->all();
        });
    }

    /** Notes by task, provider and country; the empty-string key is the base note. */
    public static function addenda(): array
    {
        return Cache::remember('ai.addenda', 60, function () {
            if (!Schema::hasTable('ai_task_prompts')) {
                return [];
            }

            $hasCountry = Schema::hasColumn('ai_task_prompts', 'country');
            $out = [];

            foreach (DB::table('ai_task_prompts')->get() as $r) {
                $scope = $hasCountry ? strtoupper((string) ($r->country ?? '')) : '';
                $out[$r->task_key][$r->provider_key][$scope] = (string) $r->addendum;
            }

            return $out;
        });
    }

    /** What this model is told for a story from this country: the base note, then the country's own. */
    public static function noteFor(string $task, string $provider, ?string $country): string
    {
        $all = self::addenda()[$task][$provider] ?? [];
        $parts = [trim((string) ($all[''] ?? ''))];

        if ($country && isset($all[strtoupper($country)])) {
            $parts[] = trim((string) $all[strtoupper($country)]);
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    public static function forget(): void
    {
        Cache::forget('ai.providers'); Cache::forget('ai.tasks'); Cache::forget('ai.addenda');
    }

    /** Before the tables exist, or if they are emptied: DeepSeek, as it always was. */
    private static function defaults(): array
    {
        $deepseek = ['key' => 'deepseek', 'name' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'vision_model' => 'deepseek-v4-flash-vision-exp',
            'key_name' => 'deepseek', 'enabled' => true, 'price_in' => 0.27, 'price_out' => 1.10, 'consecutive_failures' => 0, 'cooldown_until' => null];

        return ['providers' => ['deepseek' => $deepseek], 'tasks' => []];
    }

    public static function adapter(string $providerKey): ?GenericChatAdapter
    {
        $p = self::providers()[$providerKey] ?? null;

        return $p ? new GenericChatAdapter($p) : null;
    }

    /** Bookkeeping after a call: health on the provider row, a line in the log. */
    /**
     * What one call cost, honouring the provider's cache discount.
     *
     * ⛔⛔ THIS USED TO PRICE EVERY PROMPT TOKEN AT THE FRESH-INPUT RATE.
     *
     * DeepSeek bills input it already had cached at $0.07 per million and fresh
     * input at $0.27 - and the prompt on this project was deliberately
     * reordered so that most input IS cached. Charging all of it at $0.27
     * inflated the reported spend nearly fourfold: on Friday 4 Sep 2026 the log
     * said USD 26.29 while the balance fell by USD 5.95.
     *
     * The provider reports the split in the same usage block it reports the
     * total, so nothing extra has to be asked for - it was simply not read.
     *
     * ⛔ A provider with no confirmed cached rate (price_cached null) is priced
     * exactly as before, at price_in. Better a known number than a guessed one.
     */
    private static function costOfCall(array $p, array $usage): ?float
    {
        $in  = (int) ($usage['prompt_tokens'] ?? 0);
        $out = (int) ($usage['completion_tokens'] ?? 0);

        if ($in === 0 && $out === 0) {
            return null;
        }

        $priceIn     = (float) ($p['price_in'] ?? 0);
        $priceOut    = (float) ($p['price_out'] ?? 0);
        $priceCached = $p['price_cached'] ?? null;

        // DeepSeek's names; other providers that report a split use their own,
        // so each is looked for rather than assumed.
        $hit = $usage['prompt_cache_hit_tokens']
            ?? $usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['cache_read_input_tokens']
            ?? null;

        if ($priceCached !== null && $hit !== null) {
            $hit  = max(0, min((int) $hit, $in));
            $miss = $in - $hit;

            return round($hit / 1e6 * (float) $priceCached
                       + $miss / 1e6 * $priceIn
                       + $out / 1e6 * $priceOut, 6);
        }

        return round($in / 1e6 * $priceIn + $out / 1e6 * $priceOut, 6);
    }

    public static function record(string $task, array $p, bool $ok, int $latencyMs, ?string $error, string $reason, array $usage = [], bool $health = true): void
    {
        try {
            if (Schema::hasTable('ai_providers') && $health) {
                if ($ok) {
                    DB::table('ai_providers')->where('key', $p['key'])->update(['consecutive_failures' => 0, 'cooldown_until' => null, 'last_ok_at' => now()]);
                } else {
                    $failures = (int) ($p['consecutive_failures'] ?? 0) + 1;
                    DB::table('ai_providers')->where('key', $p['key'])->update([
                        'consecutive_failures' => $failures, 'last_error_at' => now(), 'last_error' => mb_substr((string) $error, 0, 300),
                        'cooldown_until' => $failures >= self::FAILURES_BEFORE_REST ? now()->addMinutes(self::COOLDOWN_MINUTES) : null,
                    ]);
                }
                Cache::forget('ai.providers');
            }

            if (Schema::hasTable('ai_call_log')) {
                $in  = (int) ($usage['prompt_tokens'] ?? 0);
                $out = (int) ($usage['completion_tokens'] ?? 0);

                DB::table('ai_call_log')->insert([
                    'task_key' => $task, 'provider_key' => $p['key'], 'model' => $p['model'] ?? null, 'ok' => $ok, 'latency_ms' => $latencyMs,
                    'tokens_in' => $in ?: null, 'tokens_out' => $out ?: null,
                    'cost' => self::costOfCall($p, $usage),
                    'error' => $error ? mb_substr($error, 0, 300) : null, 'reason' => $reason, 'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ai router bookkeeping failed: ' . $e->getMessage());
        }
    }
}

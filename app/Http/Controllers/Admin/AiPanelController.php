<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiRouter;
use App\Services\Ai\GenericChatAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** The AI panel: providers, tasks, per-model prompt notes, health and the last week's calls. */
class AiPanelController extends Controller
{
    public function index(): View
    {
        AiRouter::forget();
        $providers = AiRouter::providers();
        $tasks = AiRouter::tasks();
        $scope = \App\Support\BrainCountry::iso2();          // null while the switch says All countries
        $scopeName = $scope ? \App\Support\BrainCountry::name($scope) : null;
        $addenda = [];

        foreach (AiRouter::addenda() as $taskKey => $byProvider) {
            foreach ($byProvider as $providerKey => $byCountry) {
                $addenda[$taskKey][$providerKey] = (string) ($byCountry[$scope ? strtoupper($scope) : ''] ?? '');
                $base[$taskKey][$providerKey] = (string) ($byCountry[''] ?? '');
            }
        }

        $week = DB::table('ai_call_log')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('provider_key, count(*) as calls, count(*) filter (where ok) as ok, round(avg(latency_ms)) as avg_ms, coalesce(sum(cost),0) as cost')
            ->groupBy('provider_key')->get()->keyBy('provider_key');
        $byTask = DB::table('ai_call_log')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('task_key, provider_key, count(*) as calls, count(*) filter (where ok) as ok, count(*) filter (where reason in (\'failover\',\'share\')) as helped')
            ->groupBy('task_key', 'provider_key')->get()->groupBy('task_key');
        $failures = DB::table('ai_call_log')->where('ok', false)->orderByDesc('id')->limit(12)->get();
        $keys = [];
        $balances = [];
        $fresh = request()->boolean('refresh');
        foreach ($providers as $k => $p) {
            $keys[$k] = trim((string) (config('services.ai.keys.' . $p['key_name']) ?? '')) !== '';
            $balances[$k] = \App\Services\Ai\ProviderBalance::for($p, $fresh);
        }
        // one wallet per account: models on the same key share the money (owner, 4 Sep)
        $groups = [];
        foreach ($providers as $k => $p) { $groups[$p['key_name']][] = $p; }
        $wallets = [];
        $keyVars = ['kimi' => 'MOONSHOT_API_KEY', 'claude' => 'ANTHROPIC_API_KEY'];
        foreach ($groups as $acct => $group) {
            $owner = $group[0];
            foreach ($group as $g) { if (!empty($g['balance_kind'])) { $owner = $g; break; } }
            $wallets[$acct] = [
                'name'    => preg_replace('/ Reasoner$/', '', $group[0]['name']),
                'models'  => implode(' · ', array_column($group, 'model')),
                'owner'   => $owner,
                'anyOn'   => (bool) array_filter($group, fn ($g) => !empty($g['enabled'])),
                'hasKey'  => $keys[$group[0]['key']] ?? false,
                'keyVar'  => $keyVars[$acct] ?? strtoupper($acct) . '_API_KEY',
                'balance' => \App\Services\Ai\ProviderBalance::forAccount($group, $fresh),
            ];
        }
        $low = array_filter(array_map(fn ($w) => $w['balance'], $wallets), fn ($b) => $b['low'] && $b['balance'] !== null);

        // spent per day for the last 14 days, one column per account (the old Cost page, merged here 4 Sep)
        $keyOf = [];
        foreach ($providers as $k => $p) { $keyOf[$k] = $p['key_name']; }
        $rows = DB::table('ai_call_log')->where('created_at', '>=', now()->subDays(14)->startOfDay())
            ->selectRaw("to_char(created_at at time zone 'UTC' at time zone 'Asia/Kuala_Lumpur', 'YYYY-MM-DD') as day, provider_key, count(*) as calls, coalesce(sum(cost),0) as cost")
            ->groupBy('day', 'provider_key')->get();
        $daily = [];
        foreach ($rows as $r) {
            $acct = $keyOf[$r->provider_key] ?? $r->provider_key;
            $daily[$r->day][$acct]['calls'] = ($daily[$r->day][$acct]['calls'] ?? 0) + (int) $r->calls;
            $daily[$r->day][$acct]['cost'] = ($daily[$r->day][$acct]['cost'] ?? 0) + (float) $r->cost;
        }
        // DeepSeek's own bill, from its balance dropping: the figure that needs no price list
        // DeepSeek before the router existed (3 Sep): the pipeline's own per-story estimates
        $fromBalance = [];
        foreach ((new \App\Services\Ai\AiSpend())->daily(14) as $d) {
            $fromBalance[$d['day']] = $d['from_balance'];
            if (empty($daily[$d['day']]['deepseek']) && $d['estimated'] > 0) {
                $daily[$d['day']]['deepseek'] = ['calls' => $d['calls'], 'cost' => $d['estimated']];
            }
        }
        $days = [];
        for ($i = 13; $i >= 0; $i--) { $days[] = now()->timezone('Asia/Kuala_Lumpur')->subDays($i)->toDateString(); }
        $days = array_reverse($days);

        // Training: every round of the bake-off, newest first, and what is still wrong
        $training = [];
        $latestMisses = [];

        if (\Illuminate\Support\Facades\Schema::hasTable('ai_training_runs')) {
            // the story pipeline and the other tasks are different shapes and read badly mixed
            $runs = DB::table('ai_training_runs')->orderByDesc('created_at')->orderByDesc('id')->limit(120)->get();

            foreach ($runs as $r) {
                $where = ($r->task_key ?? 'enrich') === 'enrich' ? 'enrich' : 'tasks';
                $training[$where][$r->set][$r->label . '|' . ($r->task_key ?? 'enrich')][$r->provider_key] = $r;
            }

            $newest = DB::table('ai_training_runs')->where('set', 'train')->orderByDesc('created_at')->orderByDesc('id')->first();

            if ($newest) {
                $sameRound = DB::table('ai_training_runs')->where('set', 'train')->where('label', $newest->label)
                    ->where('task_key', $newest->task_key ?? 'enrich')->pluck('id', 'provider_key');
                foreach ($sameRound as $provider => $rid) {
                    $latestMisses[$provider] = DB::table('ai_training_misses')->where('run_id', $rid)->orderBy('news_item_id')->get();
                }
            }
        }

        return view('admin.brain.ai', compact('providers', 'tasks', 'addenda', 'week', 'byTask', 'failures', 'keys', 'balances', 'low', 'wallets', 'daily', 'fromBalance', 'days', 'training', 'latestMisses', 'scope', 'scopeName') + ['base' => $base ?? []]);
    }

    public function saveProvider(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:80'], 'vision_model' => ['nullable', 'string', 'max:80'], 'base_url' => ['required', 'url', 'max:200'],
            'price_in' => ['nullable', 'numeric', 'min:0'], 'price_out' => ['nullable', 'numeric', 'min:0'], 'enabled' => ['nullable'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::table('ai_providers')->where('key', $key)->update([
            'model' => $data['model'], 'vision_model' => $data['vision_model'] ?: null, 'base_url' => rtrim($data['base_url'], '/'),
            'price_in' => $data['price_in'] ?? 0, 'price_out' => $data['price_out'] ?? 0, 'enabled' => $request->boolean('enabled'), 'notes' => $data['notes'] ?? null,
            'consecutive_failures' => 0, 'cooldown_until' => null, 'updated_at' => now(),
        ]);
        AiRouter::forget();

        return back()->with('status', 'Saved ' . $key . '.');
    }

    /** What the admin topped up, and the level below which the panel warns. */
    public function saveCredit(Request $request, string $key): RedirectResponse
    {
        $data = $request->validate(['credit_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'low_balance_usd' => ['nullable', 'numeric', 'min:0', 'max:10000']]);
        $update = ['low_balance_usd' => $data['low_balance_usd'] ?? 3, 'updated_at' => now()];

        if ($request->filled('credit_usd')) {
            $update['credit_usd'] = $data['credit_usd'];
            $update['credit_set_at'] = now();   // spending is counted from this moment
        }

        DB::table('ai_providers')->where('key', $key)->update($update);
        AiRouter::forget();

        return back()->with('status', 'Balance tracking updated for ' . $key . '.');
    }

    /** A tiny prompt to the provider, timed: is the key right, is it up, how fast. */
    public function testProvider(string $key): RedirectResponse
    {
        $p = AiRouter::providers()[$key] ?? null;

        if (!$p) {
            return back()->with('status', 'No such provider.');
        }

        $adapter = new GenericChatAdapter($p);

        if (!$adapter->isConfigured()) {
            return back()->with('status', $p['name'] . ': no key in .env (' . strtoupper($p['key_name']) . '_API_KEY or the name shown under Keys).');
        }

        $t0 = microtime(true);

        try {
            $reply = $adapter->complete('Reply with exactly the two words: ready now', 0.0);
            $ms = (int) ((microtime(true) - $t0) * 1000);
            AiRouter::record('panel_test', $p, true, $ms, null, 'test', $adapter->lastUsage());

            return back()->with('status', $p['name'] . ' answered in ' . $ms . ' ms: "' . mb_substr(trim($reply), 0, 60) . '"');
        } catch (\Throwable $e) {
            AiRouter::record('panel_test', $p, false, (int) ((microtime(true) - $t0) * 1000), $e->getMessage(), 'test');

            return back()->with('status', $p['name'] . ' FAILED: ' . mb_substr($e->getMessage(), 0, 200));
        }
    }

    public function saveTask(Request $request, string $key): RedirectResponse
    {
        $providers = array_keys(AiRouter::providers());
        $data = $request->validate([
            'primary_provider' => ['required', 'in:' . implode(',', $providers)],
            'helper_provider' => ['nullable', 'in:' . implode(',', $providers)],
            'second_helper' => ['nullable', 'in:' . implode(',', $providers)],
            'mode' => ['required', 'in:primary,failover,share'], 'share_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'], 'enabled' => ['nullable'],
        ]);
        DB::table('ai_tasks')->where('key', $key)->update([
            'primary_provider' => $data['primary_provider'], 'helper_provider' => $data['helper_provider'] ?: null, 'second_helper' => $data['second_helper'] ?: null,
            'mode' => $data['mode'], 'share_threshold' => $data['share_threshold'] ?? 50, 'enabled' => $request->boolean('enabled'), 'updated_at' => now(),
        ]);
        AiRouter::forget();

        return back()->with('status', 'Saved the task.');
    }

    public function savePrompt(Request $request, string $task, string $provider): RedirectResponse
    {
        // the country switch decides which note is being edited: All countries writes the base one
        $country = \App\Support\BrainCountry::iso2();
        $text = trim((string) $request->input('addendum', ''));
        $where = fn ($q) => $country === null ? $q->whereNull('country') : $q->where('country', strtoupper($country));

        if ($text === '') {
            $where(DB::table('ai_task_prompts')->where('task_key', $task)->where('provider_key', $provider))->delete();
        } else {
            $existing = $where(DB::table('ai_task_prompts')->where('task_key', $task)->where('provider_key', $provider))->first();

            if ($existing) {
                DB::table('ai_task_prompts')->where('id', $existing->id)->update(['addendum' => mb_substr($text, 0, 8000), 'updated_at' => now()]);
            } else {
                DB::table('ai_task_prompts')->insert(['task_key' => $task, 'provider_key' => $provider, 'country' => $country ? strtoupper($country) : null,
                    'addendum' => mb_substr($text, 0, 8000), 'updated_at' => now(), 'created_at' => now()]);
            }
        }
        AiRouter::forget();

        return back()->with('status', 'Saved the notes for ' . $provider . ' on ' . $task . ($country ? ' in ' . \App\Support\BrainCountry::name($country) : ' (every country)') . '.');
    }
}

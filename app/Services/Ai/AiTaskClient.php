<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The model for one task, with its helpers behind it. Looks like a single
 * adapter to the code that uses it: complete(), completeWithImage(), post(),
 * key(), model(), isConfigured(), lastUsage() - so every caller written for
 * one DeepSeek adapter works unchanged.
 */
final class AiTaskClient implements ModelAdapter
{
    private array $usage = [];
    private ?GenericChatAdapter $answered = null;

    public function __construct(private string $task, private int $backlog = 0, private ?string $country = null)
    {
    }

    /** Which country's notes this call uses, on top of the base ones. */
    public function country(): ?string
    {
        return $this->country;
    }

    public function task(): string
    {
        return $this->task;
    }

    /** The provider that answered the last call, or the first candidate. */
    public function providerName(): string
    {
        return $this->answered?->providerKey() ?? ($this->candidates()[0][0]['key'] ?? 'none');
    }

    public function key(): string
    {
        return $this->first()?->key() ?? '';
    }

    public function model(): string
    {
        return $this->answered?->model() ?? ($this->first()?->model() ?? 'none');
    }

    public function isConfigured(): bool
    {
        return $this->first() !== null;
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }

    /**
     * @param callable|null $accept  given the reply text, returns true to keep it or a
     *                               string saying what is wrong - then the next provider is asked
     */
    public function complete(string $prompt, float $temperature = 0.3, ?callable $accept = null): string
    {
        return $this->attempt(fn (GenericChatAdapter $a, array $p) => $a->complete($this->withAddendum($prompt, $p), $temperature), $accept);
    }

    public function completeWithImage(string $prompt, string $imagePath, float $temperature = 0.1): string
    {
        return $this->attempt(fn (GenericChatAdapter $a, array $p) => $a->completeWithImage($this->withAddendum($prompt, $p), $imagePath, $temperature));
    }

    /**
     * A raw request in the caller's own payload shape; a non-2xx answer counts
     * as a failure and the next provider is tried. The last response is
     * returned either way, so callers checking successful() keep their logic.
     */
    public function post(int $timeout, array $payload, bool $vision = false, ?callable $accept = null): Response
    {
        $last = null;
        $prompt = $payload['messages'][0]['content'] ?? null;

        foreach ($this->candidates() as [$p, $reason]) {
            $adapter = new GenericChatAdapter($p);
            $sent = $payload;

            if (is_string($prompt)) {
                $sent['messages'][0]['content'] = $this->withAddendum($prompt, $p);
            }

            $t0 = microtime(true);

            try {
                $response = $adapter->post($timeout, $sent, $vision);
            } catch (\Throwable $e) {
                AiRouter::record($this->task, $p, false, (int) ((microtime(true) - $t0) * 1000), $e->getMessage(), $reason);
                continue;
            }

            $ms = (int) ((microtime(true) - $t0) * 1000);

            if ($response->successful() && is_string($response->json('choices.0.message.content'))) {
                $verdict = $accept ? $accept((string) $response->json('choices.0.message.content')) : true;

                if ($verdict === true) {
                    $this->usage = $adapter->lastUsage();
                    $this->answered = $adapter;
                    AiRouter::record($this->task, $p, true, $ms, null, $reason, $this->usage);

                    return $response;
                }

                // the model answered, but not well enough: a stronger model gets the story (no cooldown - the provider is fine)
                $last = $response;
                AiRouter::record($this->task, $p, false, $ms, 'weak answer: ' . mb_substr((string) $verdict, 0, 200), $reason === 'primary' ? 'escalated' : $reason, $adapter->lastUsage(), false);
                continue;
            }

            $last = $response;
            AiRouter::record($this->task, $p, false, $ms, 'HTTP ' . $response->status() . ': ' . mb_substr((string) $response->body(), 0, 200), $reason);
        }

        if ($last) {
            return $last;
        }

        throw new \RuntimeException('No AI provider available for task "' . $this->task . '" (none enabled, configured and out of cooldown).');
    }

    /** The reply as a decoded body with raw_content, usage, provider and model (the enrichment pipeline's shape). */
    public function raw(string $prompt, float $temperature = 0.3, int $timeout = 60, ?callable $accept = null): array
    {
        $response = $this->post($timeout, ['messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => $temperature], false, $accept);

        if (!$response->successful()) {
            throw new \RuntimeException('AI error: ' . $response->status() . ' - ' . mb_substr((string) $response->body(), 0, 300));
        }

        $body = (array) $response->json();
        $body['raw_content'] = $body['choices'][0]['message']['content'] ?? '';
        $body['provider'] = $this->providerName();
        $body['model'] = $this->model();

        return $body;
    }

    private function attempt(callable $call, ?callable $accept = null): string
    {
        $errors = [];
        $weak = null;

        foreach ($this->candidates() as [$p, $reason]) {
            $adapter = new GenericChatAdapter($p);
            $t0 = microtime(true);

            try {
                $text = $call($adapter, $p);
                $verdict = $accept ? $accept($text) : true;

                if ($verdict !== true) {
                    $weak = $text;
                    $errors[] = $p['name'] . ': weak answer (' . mb_substr((string) $verdict, 0, 120) . ')';
                    AiRouter::record($this->task, $p, false, (int) ((microtime(true) - $t0) * 1000), 'weak answer: ' . mb_substr((string) $verdict, 0, 200), $reason === 'primary' ? 'escalated' : $reason, $adapter->lastUsage(), false);
                    continue;
                }

                $this->usage = $adapter->lastUsage();
                $this->answered = $adapter;
                AiRouter::record($this->task, $p, true, (int) ((microtime(true) - $t0) * 1000), null, $reason, $this->usage);

                return $text;
            } catch (\Throwable $e) {
                $errors[] = $p['name'] . ': ' . $e->getMessage();
                AiRouter::record($this->task, $p, false, (int) ((microtime(true) - $t0) * 1000), $e->getMessage(), $reason);
            }
        }

        // every provider gave a weak answer: the best we have is still an answer
        if ($weak !== null) {
            return $weak;
        }

        throw new \RuntimeException('Every AI provider for "' . $this->task . '" failed - ' . (implode(' | ', $errors) ?: 'none enabled, configured and out of cooldown'));
    }

    /**
     * Who answers, in order. Primary first, then the helpers; anyone switched
     * off, without a key, or resting after failures is skipped. In "share"
     * mode with a backlog above the threshold, every other call starts with
     * the helper. In "primary" mode nobody else is tried.
     *
     * @return array<int, array{0: array, 1: string}>
     */
    private function candidates(): array
    {
        $task = AiRouter::tasks()[$this->task] ?? null;
        $providers = AiRouter::providers();

        $order = $task
            ? array_values(array_filter([$task['primary_provider'], $task['helper_provider'] ?? null, $task['second_helper'] ?? null]))
            : ['deepseek', 'openai'];

        if ($task && ($task['mode'] ?? 'failover') === 'primary') {
            $order = array_slice($order, 0, 1);
        }

        $usable = [];
        foreach ($order as $i => $key) {
            $p = $providers[$key] ?? null;

            if (!$p || empty($p['enabled'])) {
                continue;
            }

            if (trim((string) (config('services.ai.keys.' . $p['key_name']) ?? '')) === '') {
                continue;
            }

            if (!empty($p['cooldown_until']) && strtotime((string) $p['cooldown_until']) > time()) {
                continue;
            }

            $usable[] = [$p, $i === 0 ? 'primary' : 'failover'];
        }

        if (count($usable) > 1 && $task && ($task['mode'] ?? '') === 'share' && $this->backlog > (int) ($task['share_threshold'] ?? 50)) {
            $n = (int) Cache::increment('ai.share.' . $this->task);

            if ($n % 2 === 1) {
                $helper = array_splice($usable, 1, 1);
                $helper[0][1] = 'share';
                array_unshift($usable, $helper[0]);
            }
        }

        if (count($usable) === 1) {
            $usable[0][1] = 'only';
        }

        return $usable;
    }

    private function first(): ?GenericChatAdapter
    {
        $c = $this->candidates();

        return $c ? new GenericChatAdapter($c[0][0]) : null;
    }

    /** What this model in particular is told, on top of the shared prompt. */
    private function withAddendum(string $prompt, array $p): string
    {
        $text = AiRouter::noteFor($this->task, $p['key'], $this->country);

        return $text === '' ? $prompt : $prompt . "\n\n## Notes for " . $p['name'] . "\n" . $text;
    }
}

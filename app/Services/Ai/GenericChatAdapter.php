<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Any provider that speaks the OpenAI chat-completions shape: DeepSeek, OpenAI,
 * Moonshot (Kimi), Google's Gemini compatibility endpoint, Anthropic's
 * compatibility endpoint. Built from a row of ai_providers.
 */
final class GenericChatAdapter implements ModelAdapter
{
    private array $usage = [];

    public function __construct(private array $p)
    {
    }

    public function provider(): array
    {
        return $this->p;
    }

    public function providerKey(): string
    {
        return (string) $this->p['key'];
    }

    /**
     * The provider's NAME - "openai", "deepseek" - which is what the interface
     * asks for: "short stable name used in config, the bench and the logs".
     *
     * ⛔ This used to return the API KEY, and the interface's own comment is
     * why that was dangerous rather than merely wrong. Anything that trusts the
     * contract and logs key() logged the secret. On 4 Sep 2026 `bench:run
     * --adapter=openai` printed the whole OpenAI key to the console and tried
     * to INSERT it into bench_runs.adapter; only a varchar(40) limit stopped
     * it, and the same call for DeepSeek printed "deepseek" because that
     * adapter implemented the contract correctly. A leak that shows up for one
     * provider and not another is exactly the kind that survives review.
     *
     * The secret now has its own name, token(), which nothing prints.
     */
    public function key(): string
    {
        return (string) $this->p['key'];
    }

    /** The API key. Never logged, never stored, never returned by key(). */
    private function token(): string
    {
        return (string) (config('services.ai.keys.' . $this->p['key_name']) ?? '');
    }

    public function model(): string
    {
        return (string) $this->p['model'];
    }

    public function visionModel(): string
    {
        return (string) ($this->p['vision_model'] ?: $this->p['model']);
    }

    public function isConfigured(): bool
    {
        return trim($this->token()) !== '';
    }

    public function complete(string $prompt, float $temperature = 0.3): string
    {
        return $this->ask([['type' => 'text', 'text' => $prompt]], $temperature, false);
    }

    /** The prompt with a photo beside it, as a data URL, to the provider's vision model. */
    public function completeWithImage(string $prompt, string $imagePath, float $temperature = 0.1): string
    {
        $bytes = @file_get_contents($imagePath);
        $mime  = $bytes ? (@getimagesizefromstring($bytes)['mime'] ?? 'image/jpeg') : null;

        if (!$bytes || !$mime) {
            return $this->complete($prompt, $temperature);
        }

        return $this->ask([
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'detail' => 'low']],
        ], $temperature, true);
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }

    /**
     * A raw chat-completions request with the caller's own payload: the model
     * is this provider's, and the provider's quirks are applied. Returns the
     * HTTP response so code written against DeepSeek's response keeps working.
     */
    public function post(int $timeout, array $payload, bool $vision = false): Response
    {
        $payload['model'] = $vision ? $this->visionModel() : $this->model();
        $payload = $this->quirks($payload);

        $response = Http::withToken($this->token())->timeout($timeout)->post(rtrim($this->p['base_url'], '/') . '/chat/completions', $payload);

        if ($response->successful()) {
            $this->usage = (array) $response->json('usage', []);
        }

        return $response;
    }

    private function ask(array $content, float $temperature, bool $vision): string
    {
        $response = $this->post(90, ['messages' => [['role' => 'user', 'content' => $content]], 'temperature' => $temperature], $vision);

        if (!$response->successful()) {
            throw new \RuntimeException($this->p['name'] . ' ' . $response->status() . ': ' . mb_substr((string) $response->body(), 0, 300));
        }

        $text = $response->json('choices.0.message.content');

        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException($this->p['name'] . ' returned no content.');
        }

        return $text;
    }

    /** What each family insists on. */
    private function quirks(array $payload): array
    {
        $model = (string) $payload['model'];

        if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1') || str_starts_with($model, 'o3') || str_starts_with($model, 'o4')) {
            unset($payload['temperature']);                    // the reasoning family takes no temperature
            $payload['reasoning_effort'] = $payload['reasoning_effort'] ?? 'minimal';
            if (isset($payload['max_tokens'])) { $payload['max_completion_tokens'] = $payload['max_tokens']; unset($payload['max_tokens']); }
        }

        return $payload;
    }
}

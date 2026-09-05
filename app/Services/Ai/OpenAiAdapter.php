<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI chat completions, the same shape as DeepSeekAdapter so the two
 * are interchangeable: complete(prompt) -> string. Adds an image to the
 * prompt when given (gpt-5-nano reads photos). GPT-5 models take no
 * temperature; reasoning is kept minimal for speed and cost.
 */
final class OpenAiAdapter
{
    private array $usage = [];

    public function __construct(private ?string $model = null)
    {
        $this->model = $model ?: (string) config('services.openai.model', 'gpt-5-nano');
    }

    /** The provider's NAME, as the interface asks for - not the secret. */
    public function key(): string
    {
        return 'openai';
    }

    /** The API key. Never logged, never stored, never returned by key(). */
    private function token(): string
    {
        return (string) config('services.openai.key', '');
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    public function complete(string $prompt, float $temperature = 0.3): string
    {
        return $this->ask([['type' => 'text', 'text' => $prompt]]);
    }

    /** The prompt with a photo beside it (JPEG/PNG/WebP/GIF from disk), sent as a data URL. */
    public function completeWithImage(string $prompt, string $imagePath, float $temperature = 0.1): string
    {
        $bytes = @file_get_contents($imagePath);
        $mime  = $bytes ? (@getimagesizefromstring($bytes)['mime'] ?? 'image/jpeg') : null;

        if (!$bytes || !$mime) {
            return $this->complete($prompt);
        }

        return $this->ask([
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'detail' => 'low']],
        ]);
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }

    private function ask(array $content): string
    {
        $body = [
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'response_format' => ['type' => 'json_object'],
            'max_completion_tokens' => 1500,
        ];

        if (str_starts_with($this->model, 'gpt-5')) {
            $body['reasoning_effort'] = 'minimal';
        } else {
            $body['temperature'] = 0.1;
        }

        $r = Http::withToken($this->token())->timeout((int) config('services.openai.timeout', 40))
            ->post(rtrim((string) config('services.openai.base', 'https://api.openai.com/v1'), '/') . '/chat/completions', $body);

        if (!$r->successful()) {
            throw new \RuntimeException('OpenAI ' . $r->status() . ': ' . mb_substr((string) $r->body(), 0, 200));
        }

        $u = $r->json('usage') ?: [];
        $this->usage = ['tokens_in' => (int) ($u['prompt_tokens'] ?? 0), 'tokens_out' => (int) ($u['completion_tokens'] ?? 0),
            'cache_hit' => (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0)];

        return (string) $r->json('choices.0.message.content');
    }
}

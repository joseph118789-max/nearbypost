<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * The model this site runs on today.
 *
 * Written as an adapter rather than left inline so that the day another brand
 * is tried, the question is "does it score better on the bench" rather than
 * "how much of the pipeline do we have to rewrite".
 */
class DeepSeekAdapter implements ModelAdapter
{
    private const ENDPOINT = 'https://api.deepseek.com/v1/chat/completions';
    private const TIMEOUT = 60;

    private array $usage = [];

    public function __construct(private string $model = 'deepseek-chat')
    {
    }

    public function key(): string
    {
        return 'deepseek';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return trim((string) config('services.deepseek.key')) !== '';
    }

    public function complete(string $prompt, float $temperature = 0.3): string
    {
        $apiKey = (string) config('services.deepseek.key');

        if (trim($apiKey) === '') {
            throw new \RuntimeException('No DeepSeek key configured.');
        }

        $response = Http::withToken($apiKey)
            ->timeout(self::TIMEOUT)
            ->post(self::ENDPOINT, [
                'model'       => $this->model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => $temperature,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('DeepSeek ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('DeepSeek returned no content.');
        }

        $this->usage = (array) $response->json('usage', []);

        return $content;
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }
}

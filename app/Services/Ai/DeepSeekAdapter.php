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

    /**
     * The prompt with a photo beside it, through DeepSeek's vision model
     * (deepseek-v4-flash-vision-exp; DEEPSEEK_VISION_MODEL to change). Same
     * OpenAI-style content array: text, then the image as a data URL.
     * Owner, 3 Sep: "can switch to deepseek v4 for community post vetting?"
     */
    public function completeWithImage(string $prompt, string $imagePath, float $temperature = 0.1): string
    {
        $apiKey = (string) config('services.deepseek.key');
        $bytes  = @file_get_contents($imagePath);
        $mime   = $bytes ? (@getimagesizefromstring($bytes)['mime'] ?? null) : null;

        if (trim($apiKey) === '' || !$bytes || !$mime) {
            return $this->complete($prompt, $temperature);
        }

        $response = Http::withToken($apiKey)
            ->timeout(self::TIMEOUT)
            ->post(self::ENDPOINT, [
                'model'       => (string) config('services.deepseek.vision_model', 'deepseek-v4-flash-vision-exp'),
                'messages'    => [['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]],
                ]]],
                'temperature' => $temperature,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('DeepSeek vision ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('DeepSeek vision returned no content.');
        }

        $this->usage = (array) $response->json('usage', []);

        return $content;
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }
}

<?php

namespace App\Jobs;

use App\Models\NewsItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class EnrichArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;
    public int $timeout = 180;

    private const PROMPT_VERSION = 'v1';
    private const VALID_CATEGORIES = [
        'Property & Real Estate',
        'Food & Lifestyle',
        'Infrastructure',
        'Transport & Mobility',
        'Crime & Safety',
        'Environment',
        'Education',
        'Health',
        'Travel',
        'Entertainment / Arts & Culture',
        'Charity & Nonprofits',
        'Weather',
        'Defense & Military',
        'Markets & Finance',
        'Business & Corporate',
        'Technology & Digital',
        'Automotive',
        'Government & Policy',
        'Science',
        'Sports',
        'Religion',
        'other',
    ];

    public function handle(): void
    {
        $newsItem = NewsItem::find($this->newsItemId);

        if (!$newsItem) {
            Log::warning('EnrichArticleJob: News item not found', ['id' => $this->newsItemId]);
            return;
        }

        Log::info('ai_enrichment.start', [
            'news_item_id' => $newsItem->id,
            'url' => $newsItem->url,
        ]);

        $newsItem->update(['ai_status' => 'processing']);

        try {
            $input = [
                'title' => $newsItem->extracted_title ?? $newsItem->title,
                'source' => $newsItem->source,
                'published_at' => $newsItem->published_at?->toISOString(),
                'category' => $newsItem->primary_category,
                'summary' => $newsItem->extracted_summary ?? $newsItem->summary,
                'text' => $newsItem->extracted_text,
            ];

            $result = $this->callAiApi($input);

            $tokensIn = $this->estimateTokens(json_encode($input));
            $tokensOut = $this->estimateTokens(json_encode($result));
            $estimatedCost = $this->calculateCost($tokensIn, $tokensOut);

            // Validate and normalize AI response
            $isArticle = isset($result['is_article']) ? (bool) $result['is_article'] : true;
            $category = in_array($result['category'] ?? '', self::VALID_CATEGORIES) ? $result['category'] : 'other';
            $lat = isset($result['lat']) && is_numeric($result['lat']) ? (float) $result['lat'] : null;
            $lng = isset($result['lng']) && is_numeric($result['lng']) ? (float) $result['lng'] : null;

            // Validate coordinate ranges for Malaysia (roughly)
            if ($lat !== null && ($lat < 0.8 || $lat > 7.5 || $lng < 99.5 || $lng > 119.5)) {
                $lat = null;
                $lng = null;
            }

            // Build update data
            $updateData = [
                'ai_summary' => $result['summary'] ?? null,
                'ai_category' => $category,
                'main_place_text' => $result['main_place_text'] ?? null,
                'relevance_mode' => $result['relevance_mode'] ?? 'category_only',
                'is_article' => $isArticle,
                'validated_category' => $category,
                'validated_summary' => $result['summary'] ?? null,
                'ai_status' => 'success',
                'ai_processed_at' => now(),
                'ai_model' => 'deepseek-chat',
                'ai_prompt_version' => self::PROMPT_VERSION,
                'ai_tokens_in' => $tokensIn,
                'ai_tokens_out' => $tokensOut,
                'ai_estimated_cost' => $estimatedCost,
            ];

            // Add lat/lng if valid coordinates
            if ($lat !== null && $lng !== null) {
                $updateData['lat'] = $lat;
                $updateData['lng'] = $lng;
            }

            // If is_article is true, set status to active
            if ($isArticle) {
                $updateData['status'] = 'active';
            }

            $newsItem->update($updateData);

            Log::info('ai_enrichment.success', [
                'news_item_id' => $newsItem->id,
                'is_article' => $isArticle,
                'category' => $category,
                'lat' => $lat,
                'lng' => $lng,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'estimated_cost' => $estimatedCost,
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->handleFailure($newsItem, 'network_error: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->handleFailure($newsItem, 'ai_error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function callAiApi(array $input): array
    {
        $apiKey = config('services.deepseek.key');
        $model = config('services.deepseek.model', 'deepseek-chat');

        if (empty($apiKey)) {
            Log::warning('EnrichArticleJob: No DeepSeek API key configured, using fallback');
            return $this->fallbackEnrichment($input);
        }

        $prompt = $this->buildPrompt($input);

        try {
            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.deepseek.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a precise news analysis API. Return ONLY valid JSON - no markdown, no explanation.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 1200,
                'temperature' => 0.2,
            ]);

            if (!$response->successful()) {
                throw new \Exception('DeepSeek API error: ' . $response->status());
            }

            $raw = $response['choices'][0]['message']['content'];
            $raw = preg_replace('/^```json\s*/', '', $raw);
            $raw = preg_replace('/\s*```$/s', '', $raw);
            $result = json_decode(trim($raw), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('JSON decode failed, using fallback', ['raw' => substr($raw, 0, 200)]);
                return $this->fallbackEnrichment($input);
            }

            return $result ?? $this->fallbackEnrichment($input);

        } catch (\Exception $e) {
            Log::warning('DeepSeek API call failed, using fallback', ['error' => $e->getMessage()]);
            return $this->fallbackEnrichment($input);
        }
    }

    private function fallbackEnrichment(array $input): array
    {
        return [
            'is_article' => true,
            'is_malaysia_relevant' => true,
            'summary' => substr($input['summary'] ?? $input['title'] ?? '', 0, 200),
            'category' => $input['category'] ?? 'other',
            'main_place_text' => null,
            'gps' => 'NO',
            'lat' => null,
            'lng' => null,
            'relevance_mode' => 'category_only',
        ];
    }

    private function buildPrompt(array $input): string
    {
        return <<<PROMPT
You are a precise Malaysian news analysis API. Analyze this news article and return ONLY valid JSON.

CONTENT VALIDATION:
- is_article: Is this genuine news content (true) or a navigation page, tag page, category listing, author page, or non-content page (false)?
- Only mark as true if it's a real article with substantive content.

MALAYSIA RELEVANCE:
- is_malaysia_relevant: Is this content about Malaysia or relevant to Malaysian readers?

CATEGORY MAPPING (pick closest from this list):
Property & Real Estate, Food & Lifestyle, Infrastructure, Transport & Mobility, Crime & Safety, Environment, Education, Health, Travel, Entertainment / Arts & Culture, Charity & Nonprofits, Weather, Defense & Military, Markets & Finance, Business & Corporate, Technology & Digital, Automotive, Government & Policy, Science, Sports, Religion, other

LOCATION EXTRACTION:
- If the article mentions specific Malaysian places (cities, towns, neighborhoods), extract main_place_text and GPS coordinates.
- gps: Set to "YES" if the article mentions a specific Malaysian location with identifiable GPS coordinates. "NO" if it's purely national in scope or no specific location.
- lat/lng: Return decimal coordinates (e.g., lat=3.1390, lng=101.6869 for Kuala Lumpur) if gps="YES". Use null if gps="NO" or no specific location.

SUMMARY: A concise 2-3 sentence summary of the key news points.

RELEVANCE_MODE:
- location_only: Article is about a specific Malaysian location
- category_only: Article is about Malaysia but no specific location mentioned
- hybrid: Both specific location and broader Malaysian relevance

Article to analyze:
Title: {$input['title']}
Source: {$input['source']}
Published: {$input['published_at']}
Category: {$input['category']}
Existing Summary: {$input['summary']}
Content: {$input['text']}

Return EXACTLY this JSON structure (no markdown, no explanation):
{"is_article":true/false,"is_malaysia_relevant":true/false,"category":"Category Name","summary":"2-3 sentence summary","main_place_text":"Place Name or null","gps":"YES or NO","lat":number or null,"lng":number or null,"relevance_mode":"location_only or category_only or hybrid"}
PROMPT;
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    private function calculateCost(int $tokensIn, int $tokensOut): float
    {
        // DeepSeek pricing (approximate)
        $pricePer1kInput = 0.00027;
        $pricePer1kOutput = 0.0011;
        return round(($tokensIn * $pricePer1kInput + $tokensOut * $pricePer1kOutput) / 1000, 6);
    }

    private function handleFailure(NewsItem $newsItem, string $reason): void
    {
        $newsItem->update(['ai_status' => 'failed']);

        Log::error('ai_enrichment.failed', [
            'news_item_id' => $newsItem->id,
            'reason' => $reason,
        ]);
    }

    public function __construct(private int $newsItemId) {}
}

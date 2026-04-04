<?php

namespace App\Services;

use App\Models\TopicEntity;
use App\Models\NewsItem;
use App\Models\ExtractionJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TopicGraphService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key', config('services.openrouter.key', ''));
        $this->model  = config('services.openai.model', 'gpt-4o-mini');
    }

    /**
     * Extract entities from a news item.
     * Returns array of entity data (not saved).
     */
    public function extractFromItem(NewsItem $item): array
    {
        // Get extraction job data if exists
        $extractionJob = ExtractionJob::where('news_item_id', $item->id)->first();
        $text = '';
        
        if ($extractionJob) {
            $text = $extractionJob->extracted_text
                ?? $extractionJob->extracted_summary
                ?? '';
        }
        
        // Fallback to news item fields
        if (empty($text)) {
            $text = $item->extracted_text 
                ?? $item->extracted_summary 
                ?? $item->summary 
                ?? '';
        }

        $title = $extractionJob->extracted_title ?? $item->extracted_title ?? $item->title ?? '';

        $entities = $this->extractEntities($title . "\n\n" . $text);

        // Infer topic tag from category + keywords
        $topicTag = $this->inferTopicTag($item, $entities);

        $results = [];
        foreach ($entities as $entity) {
            $results[] = [
                'news_item_id'  => $item->id,
                'type'   => $entity['type'],  // Use 'type' for database
                'name'          => $entity['name'],
                'normalized_name' => strtolower(trim($entity['name'])),
                'topic_tag'     => $topicTag,
                'confidence'    => $entity['confidence'] ?? 'medium',
            ];
        }

        return $results;
    }

    /**
     * Main entity extraction - tries AI first, falls back to rules
     */
    public function extractEntities(string $text): array
    {
        if (empty(trim($text))) return [];

        // Fallback rule-based extraction if no API key
        if (empty($this->apiKey)) {
            return $this->ruleBasedExtraction($text);
        }

        // Try AI extraction
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'    => $this->model,
                    'messages' => [['role' => 'user', 'content' => $this->buildPrompt($text)]],
                    'temperature' => 0.2,
                ]);

            if (!$response->successful()) {
                Log::warning('TopicGraph: OpenAI failed', ['status' => $response->status()]);
                return $this->ruleBasedExtraction($text);
            }

            $content = $response['choices'][0]['message']['content'] ?? '';
            return $this->parseEntityResponse($content);
        } catch (\Exception $e) {
            Log::warning('TopicGraph exception', ['error' => $e->getMessage()]);
            return $this->ruleBasedExtraction($text);
        }
    }

    private function buildPrompt(string $text): string
    {
        $truncated = mb_substr($text, 0, 3000);
        return <<<PROMPT
From the article below, extract named entities (people, organisations, locations, events, brands).
Respond with ONLY valid JSON array (no markdown):
[
  {"name": "entity name", "type": "person|organisation|location|event|brand", "confidence": 0.85}
]

Article:
{$truncated}
PROMPT;
    }

    private function parseEntityResponse(string $content): array
    {
        // Remove markdown code blocks
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);
        if (!is_array($parsed)) return [];

        $validTypes = ['person', 'organisation', 'location', 'event', 'brand', 'topic'];
        
        return array_filter($parsed, function($e) use ($validTypes) {
            return !empty($e['name']) 
                && in_array($e['type'] ?? '', $validTypes, true);
        });
    }

    /**
     * Rule-based fallback extraction using common patterns
     */
    private function ruleBasedExtraction(string $text): array
    {
        $entities = [];

        // Common Malaysian locations
        $locations = [
            'Kuala Lumpur', 'Klang Valley', 'Petaling Jaya', 'Shah Alam', 
            'Penang', 'Johor Bahru', 'Johor', 'Selangor', 'Perak', 'Kelantan',
            'Kedah', 'Pahang', 'Melaka', 'Seremban', 'Kota Kinabalu', 
            'Ipoh', 'Kuching', 'Putrajaya', 'Cyberjaya', 'Sabah', 'Sarawak'
        ];
        
        foreach ($locations as $loc) {
            if (stripos($text, $loc) !== false) {
                $entities[] = ['name' => $loc, 'type' => 'location', 'confidence' => 'high'];
            }
        }

        // Topic keywords
        $topicKeywords = [
            'budget' => 'budget',
            'flood' => 'flood-relief',
            'relief' => 'flood-relief',
            'fire' => 'fire-disaster',
            'accident' => 'accident',
            'crime' => 'crime',
            'politics' => 'politics',
            'election' => 'election',
            'layoff' => 'tech-layoffs',
            'job cut' => 'tech-layoffs',
            'economy' => 'economy',
            'health' => 'health',
            'weather' => 'weather',
        ];

        foreach ($topicKeywords as $keyword => $tag) {
            if (stripos($text, $keyword) !== false) {
                $entities[] = ['name' => $tag, 'type' => 'topic', 'confidence' => 'high'];
            }
        }

        return array_slice($entities, 0, 15);
    }

    private function inferTopicTag(NewsItem $item, array $entities): ?string
    {
        // Use primary category as topic tag
        if (!empty($item->primary_category)) {
            return $item->primary_category;
        }

        // Check entity names for topic keywords
        foreach ($entities as $e) {
            if ($e['type'] === 'topic') {
                return $e['name'];
            }
        }

        return null;
    }

    /**
     * Get all unique entities of a specific type across all news items
     */
    public function getEntitiesByType(string $type, int $limit = 100): Collection
    {
        return TopicEntity::where('type', $type)
            ->select('normalized_name', DB::raw('COUNT(*) as item_count'), DB::raw('SUM(occurrence_count) as total_mentions'))
            ->groupBy('normalized_name')
            ->orderByDesc('item_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get related entities for a specific entity name
     */
    public function getRelatedEntities(string $normalizedName, int $limit = 10): Collection
    {
        $newsItemIds = TopicEntity::where('normalized_name', $normalizedName)
            ->pluck('news_item_id');

        return TopicEntity::whereIn('news_item_id', $newsItemIds)
            ->where('normalized_name', '!=', $normalizedName)
            ->select('type', 'normalized_name', DB::raw('COUNT(*) as occurrences'))
            ->groupBy('type', 'normalized_name')
            ->orderByDesc('occurrences')
            ->limit($limit)
            ->get();
    }

    /**
     * Get popular topic clusters from recent news
     */
    public function getPopularTopics(int $hours = 24, int $limit = 20): Collection
    {
        $recentItemIds = NewsItem::where('published_at', '>=', now()->subHours($hours))
            ->pluck('id');

        return TopicEntity::whereIn('news_item_id', $recentItemIds)
            ->where('type', 'topic')
            ->select('normalized_name', DB::raw('COUNT(*) as item_count'))
            ->groupBy('normalized_name')
            ->orderByDesc('item_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get topic statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_entities' => TopicEntity::count(),
            'by_type' => TopicEntity::select('type', DB::raw('COUNT(*) as count'))
                ->groupBy('type')
                ->pluck('count', 'type'),
            'processed_articles' => NewsItem::where('topic_extraction_status', 'success')->count(),
            'pending_articles' => NewsItem::where(function ($q) {
                $q->whereNull('topic_extraction_status')
                  ->orWhereNot('topic_extraction_status', 'success');
            })->whereHas('feedReadyItem')->count(),
        ];
    }

    /**
     * Search entities by name pattern
     */
    public function search(string $query, int $limit = 20): Collection
    {
        return TopicEntity::where('normalized_name', 'like', '%' . strtolower($query) . '%')
            ->select('type', 'normalized_name', DB::raw('COUNT(*) as occurrences'))
            ->groupBy('type', 'normalized_name')
            ->orderByDesc('occurrences')
            ->limit($limit)
            ->get();
    }
}
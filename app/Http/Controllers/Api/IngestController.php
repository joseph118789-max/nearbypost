<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\RawIngest;
use App\Models\FailedIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Services\Classification\ContentPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class IngestController extends Controller
{
    private const APPROVED_DIRECT_DOMAINS = [
        'thestar.com.my',
        'nst.com.my',
        'freemalaysiatoday.com',
        'bernama.com',
        'malaymail.com',
        'thesundaily.my',
    ];


    private const BLOCKED_URL_PARTS = [
        '/tag/', '/tags/', '/category/', '/categories/', '/archive', '/archives', '/search',
        '/video/', '/videos/', '/gallery/', '/galleries/', '/photo/', '/photos/', '/topic/',
        '/topics/', '/live/',
    ];

    private const PAYWALL_HINTS = [
        'subscribe', 'subscription', 'premium', 'paywall', 'login', 'signin', 'sign-in', 'register',
    ];

    private function normalizeCategories(array $data): array
    {
        return [
            'primary_category' => $data['primary_category']
                ?? $data['category']
                ?? $data['primary_cat']
                ?? 'others',
            'secondary_category' => $data['secondary_category']
                ?? $data['secondary_cat']
                ?? $data['sub_cat']
                ?? null,
        ];
    }

    private function normalizeSource(array $data): string
    {
        $source = $data['source']
            ?? $data['source_name']
            ?? $data['source_label']
            ?? $data['_source']
            ?? $data['source_domain']
            ?? 'unknown';

        $source = trim((string) $source);
        $source = preg_replace('#^feed/#i', '', $source) ?: $source;
        $source = preg_replace('#^https?://#i', '', $source) ?: $source;
        $source = preg_replace('#^www\.#i', '', $source) ?: $source;
        $source = preg_replace('#/+$#', '', $source) ?: $source;

        if ($source === '' || strtolower($source) === 'n8n') {
            $source = $data['source_label']
                ?? $data['source_name']
                ?? $data['source_domain']
                ?? 'unknown';
        }

        return mb_substr($source, 0, 100);
    }

    private function normalizeDomain(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST) ?: $value;
        $host = strtolower((string) $host);
        $host = preg_replace('#^www\.#', '', $host) ?: $host;

        return $host !== '' ? $host : null;
    }

    private function sourceMode(array $data): string
    {
        return strtolower((string) ($data['source_mode'] ?? 'direct_seed'));
    }


    private function isBlockedUrl(string $url): bool
    {
        $url = strtolower($url);

        foreach (self::BLOCKED_URL_PARTS as $part) {
            if (str_contains($url, $part)) {
                return true;
            }
        }

        foreach (self::PAYWALL_HINTS as $hint) {
            if (str_contains($url, $hint)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Is this domain allowed to be ingested directly?
     *
     * The sources registry is where feeds are added, disabled and re-tiered, so
     * it decides what may be ingested. A constant compiled into this controller
     * cannot: registering a source would not have been enough for its articles
     * to be accepted. APPROVED_DIRECT_DOMAINS is kept as a fallback for
     * anything not yet in the registry.
     */
    private function isApprovedDomain(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        static $registered = null;

        if ($registered === null) {
            $registered = [];

            try {
                foreach (DB::table('sources')->where('is_active', true)->pluck('base_url') as $baseUrl) {
                    $host = $this->normalizeDomain($baseUrl);

                    if ($host !== null && $host !== '') {
                        $registered[] = $host;
                    }
                }
            } catch (\Throwable $e) {
                // Registry unavailable: fall back to the constant below.
                $registered = [];
            }
        }

        foreach (array_merge($registered, self::APPROVED_DIRECT_DOMAINS) as $approved) {
            if ($domain === $approved || str_ends_with($domain, '.' . $approved)) {
                return true;
            }
        }

        return false;
    }

    private function policyDecision(array $data): array
    {
        $url = (string) ($data['url'] ?? '');
        $mode = $this->sourceMode($data);
        $domain = $this->normalizeDomain($data['source_domain'] ?? $url) ?? '';

        if ($url === '') {
            return [false, 'missing_url'];
        }

        // Spec 4.2: judge the URL before spending anything on it. This is
        // the cheapest refusal available and the one that matters most now
        // that sources are discovered without a person reviewing them.
        if ($spam = (new ContentPolicy())->screenUrl($url)) {
            return [false, $spam];
        }

        if ($mode === 'direct_seed') {
            if (!$this->isApprovedDomain($domain)) {
                return [false, 'direct_domain_not_approved'];
            }
        }

        if ($mode === 'bursa_announcements') {
            if (!($domain === 'bursamalaysia.com' || str_ends_with($domain, '.bursamalaysia.com'))) {
                return [false, 'not_bursa_announcement'];
            }

            return [true, null];
        }


        if ($mode === 'google_discovery' && ($domain === '' || $domain === 'news.google.com')) {
            return [false, 'google_not_resolved_to_final_article'];
        }

        return [true, null];
    }

    /**
     * POST /api/internal/ingest/news
     * 
     * Receives normalized news from n8n
     * Input: title, url, source, published_at, summary, primary_category, secondary_category
     */
    public function ingest(Request $request): JsonResponse
    {
        $requestId = 'ingest_' . uniqid();
        $startTime = microtime(true);
        
        Log::info('ingest.start', [
            'request_id' => $requestId,
            'source' => $request->input('source'),
            'url' => $request->input('url'),
        ]);
        
        try {
            // Validate required fields
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:500',
                'url' => 'required|url|max:1000',
                'source' => 'nullable|string|max:100',
                'source_label' => 'nullable|string|max:100',
                'source_name' => 'nullable|string|max:100',
                'source_domain' => 'nullable|string|max:255',
                'published_at' => 'nullable|date',
                'summary' => 'nullable|string',
                'category' => 'nullable|string|max:100',
                'primary_category' => 'nullable|string|max:100',
                'secondary_category' => 'nullable|string|max:100',
                'location_text' => 'nullable|string|max:200',
            ]);
            
            if ($validator->fails()) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                Log::warning('ingest.validation_failed', [
                    'request_id' => $requestId,
                    'errors' => $validator->errors()->toArray(),
                    'duration_ms' => $duration,
                ]);
                
                // Store failed payload in dead-letter bucket
                $this->storeFailedIngest($request->all(), 'validation_failed: ' . json_encode($validator->errors()->toArray()), $requestId);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
            
            $data = array_merge($request->all(), $validator->validated());
            $data['source'] = $this->normalizeSource($request->all());

            [$allowed, $reason] = $this->policyDecision($data);
            if (!$allowed) {
                $this->storeFailedIngest($request->all(), 'policy_rejected: ' . $reason, $requestId);

                return response()->json([
                    'success' => false,
                    'message' => 'Rejected by ingestion policy',
                    'reason' => $reason,
                ], 422);
            }
            
            // Check for duplicate by URL
            $existing = NewsItem::where('url', $data['url'])->first();
            if ($existing) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                Log::info('ingest.duplicate', [
                    'request_id' => $requestId,
                    'existing_id' => $existing->id,
                    'duration_ms' => $duration,
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Duplicate, already exists',
                    'existing_id' => $existing->id,
                ], 200);
            }
            
            // Store raw incoming payload for traceability
            $rawIngest = RawIngest::create([
                'source' => $data['source'],
                'raw_json_payload' => $data,
                'received_at' => now(),
                'processing_status' => 'pending',
            ]);
            
            // Create news item
            $categories = $this->normalizeCategories($data);

            $createData = [
                'title' => $data['title'],
                'url' => $data['url'],
                'source' => $data['source'],
                'published_at' => $data['published_at'] ?? null,
                'summary' => $data['summary'] ?? null,
                'primary_category' => $categories['primary_category'],
                'secondary_category' => $categories['secondary_category'],
                'status' => 'pending_extraction',
            ];
            if (!empty($data['location_text'])) {
                $createData['main_place_text'] = $data['location_text'];
            }
            $newsItem = NewsItem::create($createData);
            
            // Update raw ingest status
            $rawIngest->update(['processing_status' => 'processed']);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('ingest.success', [
                'request_id' => $requestId,
                'news_item_id' => $newsItem->id,
                'source' => $data['source'],
                'duration_ms' => $duration,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'News item ingested',
                'data' => [
                    'id' => $newsItem->id,
                    'title' => $newsItem->title,
                ],
            ], 201);
            
        } catch (\Illuminate\Database\QueryException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error('ingest.database_error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            
            $this->storeFailedIngest($request->all(), 'database_error: ' . $e->getMessage(), $requestId);
            
            return response()->json([
                'success' => false,
                'message' => 'Database error during ingestion',
            ], 500);
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error('ingest.unexpected_error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            
            $this->storeFailedIngest($request->all(), 'unexpected_error: ' . $e->getMessage(), $requestId);
            
            return response()->json([
                'success' => false,
                'message' => 'Could not ingest news item',
            ], 500);
        }
    }

    /**
     * POST /api/internal/ingest/batch
     * Accepts an array of normalized items from n8n.
     */
    public function ingestBatch(Request $request): JsonResponse
    {
        $requestId = 'batch_' . uniqid();
        $items = $request->input('items', []);

        if (!is_array($items) || empty($items)) {
            return response()->json([
                'success' => false,
                'message' => 'items must be a non-empty array',
            ], 422);
        }

        $results = [];

        foreach ($items as $index => $payload) {
            $data = is_array($payload) ? $payload : [];

            $validator = Validator::make($data, [
                'title' => 'required|string|max:500',
                'url' => 'required|url|max:1000',
                'source' => 'nullable|string|max:100',
                'source_label' => 'nullable|string|max:100',
                'source_name' => 'nullable|string|max:100',
                'source_domain' => 'nullable|string|max:255',
                'published_at' => 'nullable|date',
                'summary' => 'nullable|string',
                'category' => 'nullable|string|max:100',
                'primary_category' => 'nullable|string|max:100',
                'secondary_category' => 'nullable|string|max:100',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
            ]);

            if ($validator->fails()) {
                $this->storeFailedIngest($data, 'batch_validation_failed: ' . json_encode($validator->errors()->toArray()), $requestId . '_' . $index);
                $results[] = ['index' => $index, 'success' => false, 'error' => 'validation_failed'];
                continue;
            }

            $validated = array_merge($data, $validator->validated());
            $validated['source'] = $this->normalizeSource($data);

            [$allowed, $reason] = $this->policyDecision($validated);
            if (!$allowed) {
                $this->storeFailedIngest($data, 'batch_policy_rejected: ' . $reason, $requestId . '_' . $index);
                $results[] = ['index' => $index, 'success' => false, 'error' => $reason];
                continue;
            }

            $existing = NewsItem::where('url', $validated['url'])->first();
            if ($existing) {
                $results[] = ['index' => $index, 'success' => true, 'duplicate' => true, 'id' => $existing->id];
                continue;
            }

            $categories = $this->normalizeCategories($validated);

            $createData = [
                'title' => $validated['title'],
                'url' => $validated['url'],
                'source' => $validated['source'],
                'published_at' => $validated['published_at'] ?? null,
                'summary' => $validated['summary'] ?? null,
                'primary_category' => $categories['primary_category'],
                'secondary_category' => $categories['secondary_category'],
                'status' => 'pending_extraction',
            ];

            if (array_key_exists('latitude', $validated)) {
                $createData['latitude'] = $validated['latitude'];
            }
            if (array_key_exists('longitude', $validated)) {
                $createData['longitude'] = $validated['longitude'];
            }

            $newsItem = NewsItem::create($createData);
            $results[] = ['index' => $index, 'success' => true, 'id' => $newsItem->id];
        }

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'results' => $results,
        ], 201);
    }
    

    /**
     * POST /api/internal/ingest/classify
     * DeepSeek-powered Malaysia relevance + article classification + location extraction.
     */
    public function classifyWithDeepSeek(Request $request): JsonResponse
    {
        $items = $request->input('items', []);

        if (!is_array($items) || empty($items)) {
            return response()->json(['success' => false, 'message' => 'items must be a non-empty array'], 422);
        }

        $apiKey = env('DEEPSEEK_API_KEY');
        if (empty($apiKey)) {
            return response()->json(['success' => false, 'message' => 'DeepSeek API key not configured'], 500);
        }

        $systemPrompt = "You are an AI assistant for a Malaysia local news aggregation app called Nearbypost. Your task is to analyze news article metadata (title, summary, source URL) and classify each article.";

        $results = [];
        $client = new \GuzzleHttp\Client(['timeout' => 60]);

        foreach ($items as $i => $item) {
            $title = $this->stripHtml(($item['title'] ?? 'Untitled'));
            $summary = $this->stripHtml(($item['summary'] ?? ''));
            $url = $item['url'] ?? '';
            $source = $item['source'] ?? '';

            $userPrompt = "Analyze this article and return ONLY a valid JSON object (no markdown, no explanation). " . json_encode([
                'index' => $i,
                'url' => $url,
                'source' => $source,
                'title' => $title,
                'summary' => substr($summary, 0, 300),
            ]) . "\n\nFor this article, determine:\n1. is_malaysia_relevant (boolean): Is this article about Malaysia or Malaysians? Include Malaysian politics, economy, cities, states, events, companies, people, culture, sports, crime, etc. Exclude foreign news unless Malaysia has direct involvement.\n2. is_article (boolean): Is this a real news article with substantive content? Exclude tag/category/archive pages, promotional content, opinion pieces.\n3. primary_category (string): One of: business, crime, education, entertainment, features, health, nation, politics, sports, technology, weather, world. Pick the closest fit.\n4. secondary_category (string): A more specific sub-category, or general as fallback.\n5. location_text (string): Malaysian city, state, or region (e.g. Kuala Lumpur, Selangor, Penang, Johor, Sabah, Sarawak, Putrajaya, Perak, Kedah, Kelantan, Terengganu, Pahang, Melaka, Negeri Sembilan, Malaysia).\n6. confidence (float): 0.0 to 1.0, how confident are you in this classification?\n\nIf is_article is false OR is_malaysia_relevant is false, set confidence to 0.0.\nFor sports articles, include football, badminton, Malaysian leagues, athletes.\nFor business, include stock market, economy, banking, corporate news.\n\nReturn ONLY a JSON object like: {\"index\":0,\"is_malaysia_relevant\":true,\"is_article\":true,\"primary_category\":\"sports\",\"secondary_category\":\"football\",\"location_text\":\"Kuala Lumpur\",\"confidence\":0.95}";

            try {
                $response = $client->post('https://api.deepseek.com/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'json' => [
                        'model' => 'deepseek-chat',
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                        'temperature' => 0.1,
                        'max_tokens' => 256,
                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                $content_text = $body['choices'][0]['message']['content'] ?? '';
                $parsed = $this->extractJson($content_text);

                if ($parsed !== null && isset($parsed['index'])) {
                    $results[] = $parsed;
                } else {
                    Log::warning('DeepSeek per-item: failed to parse item ' . $i, ['raw' => substr($content_text, 0, 200)]);
                    $results[] = [
                        'index' => $i,
                        'is_malaysia_relevant' => false,
                        'is_article' => false,
                        'primary_category' => 'nation',
                        'secondary_category' => 'general',
                        'location_text' => 'Malaysia',
                        'confidence' => 0.0,
                        '_parse_error' => true,
                    ];
                }
            } catch (\Exception $e) {
                Log::error('DeepSeek per-item call failed for index ' . $i, ['error' => $e->getMessage()]);
                $results[] = [
                    'index' => $i,
                    'is_malaysia_relevant' => false,
                    'is_article' => false,
                    'primary_category' => 'nation',
                    'secondary_category' => 'general',
                    'location_text' => 'Malaysia',
                    'confidence' => 0.0,
                    '_error' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['success' => true, 'classifications' => $results], 200);
    }


    private function stripHtml(string $text): string
    {
        // First decode HTML entities (including numeric entities like &#8217;)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Then strip HTML tags
        return trim(preg_replace('/<[^>]*>/', ' ', str_replace(['&nbsp;'], [' '], $text)));
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }
        // Try to find JSON array in text
        if (preg_match('/\[\s*\{/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $jsonStr = substr($text, $start);
            $depth = 0;
            for ($i = 0; $i < strlen($jsonStr); $i++) {
                if ($jsonStr[$i] === '{') $depth++;
                elseif ($jsonStr[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $jsonStr = substr($jsonStr, 0, $i + 1);
                        break;
                    }
                }
            }
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Store failed ingestion in dead-letter bucket
     */
    private function storeFailedIngest(array $payload, string $reason, string $requestId): void
    {
        try {
            FailedIngest::create([
                'source' => $payload['source'] ?? 'unknown',
                'url' => $payload['url'] ?? null,
                'title' => $payload['title'] ?? null,
                'raw_payload' => $payload,
                'failure_reason' => $reason,
                'retry_count' => 0,
                'failed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store failed ingest record', ['error' => $e->getMessage()]);
        }
    }
}

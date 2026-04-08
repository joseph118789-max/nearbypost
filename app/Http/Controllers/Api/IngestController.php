<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\RawIngest;
use App\Models\FailedIngest;
use Illuminate\Http\JsonResponse;
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

    private const MALAYSIA_KEYWORDS = [
        'malaysia', 'malaysian', 'kuala lumpur', 'putrajaya', 'selangor', 'penang', 'johor',
        'kedah', 'kelantan', 'terengganu', 'pahang', 'perak', 'negeri sembilan', 'melaka',
        'sabah', 'sarawak', 'labuan', 'perlis', 'ringgit', 'bursa', 'anwar', 'petronas',
        'kwsp', 'epf', 'maybank', 'cimb', 'rapidkl', 'prasarana', 'bernama',
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

    private function isMalaysiaRelevant(array $data): bool
    {
        if ($this->sourceMode($data) === 'bursa_announcements') {
            return true;
        }

        $haystack = strtolower(trim(implode(' ', array_filter([
            $data['title'] ?? null,
            $data['summary'] ?? null,
            $data['url'] ?? null,
            $data['source'] ?? null,
            $data['source_name'] ?? null,
            $data['source_label'] ?? null,
        ]))));

        foreach (self::MALAYSIA_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
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

    private function looksLikeArticleUrl(string $url): bool
    {
        if ($url === '' || $this->isBlockedUrl($url)) {
            return false;
        }

        return (bool) preg_match('#/(20\d{2}|\d{4}/\d{2}/\d{2})/#', $url)
            || str_contains($url, '/news/')
            || str_contains($url, '/business/')
            || str_contains($url, '/markets/')
            || str_contains($url, '/nation/')
            || (bool) preg_match('#-[a-z0-9-]{8,}$#', $url);
    }

    private function policyDecision(array $data): array
    {
        $url = (string) ($data['url'] ?? '');
        $mode = $this->sourceMode($data);
        $domain = $this->normalizeDomain($data['source_domain'] ?? $url) ?? '';

        if ($url === '') {
            return [false, 'missing_url'];
        }

        if ($mode === 'direct_seed') {
            $allowed = collect(self::APPROVED_DIRECT_DOMAINS)
                ->contains(fn (string $approved) => $domain === $approved || str_ends_with($domain, '.' . $approved));

            if (!$allowed) {
                return [false, 'direct_domain_not_approved'];
            }
        }

        if ($mode === 'bursa_announcements') {
            if (!($domain === 'bursamalaysia.com' || str_ends_with($domain, '.bursamalaysia.com'))) {
                return [false, 'not_bursa_announcement'];
            }

            return [true, null];
        }

        if (!$this->isMalaysiaRelevant($data)) {
            return [false, 'not_malaysia_relevant'];
        }

        if ($this->isBlockedUrl($url)) {
            return [false, 'blocked_url_pattern'];
        }

        if (!$this->looksLikeArticleUrl($url)) {
            return [false, 'not_article_page'];
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

            $newsItem = NewsItem::create([
                'title' => $data['title'],
                'url' => $data['url'],
                'source' => $data['source'],
                'published_at' => $data['published_at'] ?? null,
                'summary' => $data['summary'] ?? null,
                'primary_category' => $categories['primary_category'],
                'secondary_category' => $categories['secondary_category'],
                'status' => 'pending_extraction',
            ]);
            
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
                'lat' => 'nullable|numeric|between:-90,90',
                'lng' => 'nullable|numeric|between:-180,180',
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

            if (array_key_exists('lat', $validated)) {
                $createData['lat'] = $validated['lat'];
            }
            if (array_key_exists('lng', $validated)) {
                $createData['lng'] = $validated['lng'];
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

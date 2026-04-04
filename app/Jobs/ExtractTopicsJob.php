<?php

namespace App\Jobs;

use App\Models\NewsItem;
use App\Models\TopicEntity;
use App\Services\TopicGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractTopicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 180;

    public function handle(): void
    {
        $newsItem = NewsItem::find($this->newsItemId);
        
        if (!$newsItem) {
            Log::warning('ExtractTopicsJob: News item not found', ['id' => $this->newsItemId]);
            return;
        }

        Log::info('topic_extraction.start', [
            'news_item_id' => $newsItem->id,
            'title' => substr($newsItem->title ?? '', 0, 50),
        ]);

        $service = new TopicGraphService();
        $entities = $service->extractFromItem($newsItem);

        $created = 0;
        foreach ($entities as $entity) {
            if (empty($entity['type']) || empty($entity['name'])) {
                continue;
            }

            // Use 'type' column (not entity_type) to match database schema
            TopicEntity::updateOrCreate(
                [
                    'news_item_id' => $newsItem->id,
                    'name' => $entity['name'],
                ],
                [
                    'type' => $entity['type'],
                    'normalized_name' => strtolower(trim($entity['name'])),
                    'topic_cluster' => $entity['topic_tag'] ?? null,
                    'confidence' => $entity['confidence'] ?? 'medium',
                    'occurrence_count' => 1,
                    'extraction_model' => 'rule-based',
                    'extracted_at' => now(),
                ]
            );
            $created++;
        }

        // Update status - use direct DB to avoid fillable issues
        $newsItem->update([
            'topic_extraction_status' => 'success',
            'topic_extracted_at' => now(),
            'topics_extracted' => json_encode(array_column($entities, 'name')),
        ]);

        Log::info('topic_extraction.complete', [
            'news_item_id' => $newsItem->id,
            'entities_created' => $created,
        ]);
    }

    public function __construct(
        private int $newsItemId
    ) {}
}
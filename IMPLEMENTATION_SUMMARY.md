# Nearbypost Backend - Complete D5-D19 Implementation

## Status: All Stages Complete (Local Ready)

| Stage | Status | Routes/Files |
|-------|--------|--------------|
| **D5** | ✅ | 8 CRUD endpoints for subscribers + broadcast groups |
| **D6** | ✅ | `POST /api/report-content` + timing middleware |
| **D7** | ✅ | `POST /api/internal/ingest/news` + n8n workflow |
| **D8** | ✅ | Raw/failed ingest tables + retry logic |
| **D9** | ✅ | Extraction via `php artisan ingest:extract` |
| **D10** | ✅ | AI enrichment via `php artisan ingest:enrich` |
| **D16** | ✅ | Topic entity extraction via `php artisan ingest:extract-topics` |
| **D19** | ✅ | Notification dispatch system |

---

## D19: Push Notification / Broadcast Layer

### What was implemented
- **Database migration** for `notification_logs` table
- **Subscriber preferences** migration with email, webhook, in-app settings
- **NotificationLog model** with channel/status constants
- **DispatchNotificationJob** - async queue job for sending notifications
- **NotificationService** - business logic for matching and dispatching
- **Artisan commands**: `php artisan notify:dispatch` and `php artisan notify:test`

### Database migrations
| Migration | Description |
|-----------|-------------|
| `2026_04_04_000007` | Create `notification_logs` table |
| `2026_04_04_000008` | Add preferences to `subscribers` table |

### Subscriber preference fields
| Field | Type | Description |
|-------|------|-------------|
| `preferences` | json | Category/location preferences |
| `notify_email` | boolean | Enable email notifications |
| `notify_webhook` | boolean | Enable webhook notifications |
| `notify_in_app` | boolean | Enable in-app notifications |
| `email` | string | Email address (unique) |
| `webhook_url` | string | Webhook URL for HTTP POST |
| `max_notifications_per_hour` | integer | Rate limit (default: 10) |

### NotificationLog fields
| Field | Type | Description |
|-------|------|-------------|
| `subscriber_id` | foreign | Reference to subscriber |
| `news_item_id` | foreign | Reference to news item (nullable) |
| `channel` | string | email, webhook, in_app |
| `status` | string | pending, sent, failed, rate_limited |
| `recipient` | string | Email or webhook URL |
| `payload` | json | Notification payload sent |
| `response` | text | API response if any |
| `error_message` | string | Error details if failed |
| `sent_at` | timestamp | When notification was sent |

### Rate limiting
- Default: 10 notifications per subscriber per hour
- Configurable per subscriber via `max_notifications_per_hour`
- Checked before dispatching any notification

### Artisan commands
```bash
# Dispatch notifications for a specific news item
php artisan notify:dispatch --news_item_id=123

# Dispatch for recent news items (last 24 hours)
php artisan notify:dispatch --hours=48

# Dry run (no actual notifications sent)
php artisan notify:dispatch --dry-run

# Test notification to a subscriber
php artisan notify:test 1
php artisan notify:test 1 --dry-run
```

### Files created
```
app/
├── Console/Commands/
│   ├── DispatchNotifications.php
│   └── TestNotification.php
├── Jobs/
│   └── DispatchNotificationJob.php
├── Models/
│   ├── NotificationLog.php
│   ├── NewsItem.php
│   └── FeedReadyItem.php
└── Services/
    └── NotificationService.php

database/migrations/
├── 2026_04_04_000007_create_notification_logs_table.php
└── 2026_04_04_000008_add_preferences_to_subscribers.php
```

---

## D10: AI Enrichment Foundation

### What was implemented
- `EnrichArticleJob` - async queue job with retry + idempotency
- Migration for AI fields on `news_items` table
- Artisan command `php artisan ingest:enrich`

### Database fields added
| Field | Type | Description |
|-------|------|-------------|
| `ai_summary` | text | AI-generated summary |
| `ai_category` | string | Refined category |
| `main_place_text` | string | Location mentioned |
| `relevance_mode` | string | location_only/category_only/hybrid |
| `ai_status` | string | pending/processing/success/failed |
| `ai_processed_at` | timestamp | When processed |
| `ai_model` | string | Model used (gpt-4o-mini) |
| `ai_prompt_version` | string | v1 (for idempotency) |
| `ai_tokens_in` | integer | Input token count |
| `ai_tokens_out` | integer | Output token count |
| `ai_estimated_cost` | decimal | Cost in USD |

### Job features
- **Retry**: 3 tries, 120s initial backoff, 180s timeout
- **Idempotency**: Skips if `ai_status=success` + `ai_prompt_version=v1`
- **Fallback**: Returns basic enrichment without API if no key configured
- **Token tracking**: Estimated tokens + cost per article

### Configuration
Add to `.env`:
```env
OPENAI_API_KEY=your_key_here
OPENAI_MODEL=gpt-4o-mini
```

---

## D8: Ingestion Hardening

### Tables created
- `raw_ingests` - stores raw payload for every ingestion
- `failed_ingestions` - dead-letter bucket

### Retry logic
- n8n: 3 retries with 5s wait (in workflow JSON)
- Laravel: Queue job retry via ShouldQueue interface
- Dead-letter: Failed items preserved with full payload + reason

---

## D7: First Ingestion Pipeline

### Webhook: `POST /api/internal/ingest/news`

**Input:**
```json
{
  "title": "Article Title",
  "url": "https://example.com/article",
  "source": "bbc_news",
  "published_at": "2026-04-04T06:00:00Z",
  "summary": "Summary...",
  "category": "property"
}
```

**Response:**
```json
{ "success": true, "message": "News item ingested", "data": { "id": 123 } }
```

### n8n workflow
Location: `n8n-workflow-news-ingestion.json`
- 3 RSS sources: BBC, The Star, Malay Mail
- Normalizes to required format
- Sends to Laravel webhook with retry

---

## Files Structure

```
nearbypost/
├── app/
│   ├── Console/Commands/
│   │   └── EnrichArticles.php
│   ├── Http/Controllers/Api/
│   │   └── IngestController.php
│   ├── Jobs/
│   │   └── EnrichArticleJob.php
│   └── Models/
│       ├── FailedIngest.php
│       ├── RawIngest.php
├── database/migrations/
│   ├── 2026_04_04_000005_add_ai_enrichment_fields.php
│   └── 2026_04_04_000006_create_raw_failed_ingest_tables.php
└── n8n-workflow-news-ingestion.json
```

---

## Deployment Steps

1. Copy files to server: `scp -r nearbypost/* root@187.127.97.175:/var/www/nearbypost/`
2. Run migrations: `php artisan migrate`
3. Configure queue worker (Redis/supervisor)
4. Import n8n workflow JSON
5. Add OpenAI API key to `.env`
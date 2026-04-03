# Nearbypost Backend Handover Document
> Based on actual implementation — server 187.127.97.175, /var/www/nearbypost
> Last verified: 2026-04-03

---

# Backend Foundation

## 1. System Overview

| | |
|---|---|
| **Framework** | Laravel (PHP) |
| **Database** | PostgreSQL |
| **Cache** | Redis |
| **API Base URL** | `https://nearbypost.com/api` (via nginx proxy to Laravel) |
| **Public Serving Layer** | `feed_ready_items` table only — public APIs NEVER query `news_items` |
| **Canonical Data** | `news_items` table — raw/admin data, NOT exposed directly |
| **Admin Panel** | Static HTML (`/admin.html`) — nginx HTTP Basic Auth, NOT a Laravel API |

### Architecture Rule
```
news_items  →  (sync via observer)  →  feed_ready_items  →  PUBLIC API
(raw/canonical)                        (public serving layer)
```
All public feed endpoints read exclusively from `feed_ready_items`.

---

## 2. Database Tables

### `news_items` (canonical / admin table)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `title` | varchar | Required |
| `summary` | text | Nullable |
| `source` | varchar | Nullable, e.g. "The Star" |
| `url` | varchar | Unique constraint |
| `published_at` | timestamp | Nullable |
| `primary_category` | varchar | Nullable — top-level category |
| `secondary_category` | varchar | Nullable — subtopic |
| `status` | varchar | Default `'active'` — controls sync to feed |
| `main_place_text` | varchar | Nullable |
| `lat` | decimal(10,7) | Nullable |
| `lng` | decimal(10,7) | Nullable |
| `precision_type` | varchar | Nullable — geo precision |
| `geo_confidence` | decimal(5,2) | Nullable |
| `relevance_mode` | varchar | Nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Sync behavior:** When `status = 'active'` → synced to `feed_ready_items`. Any other status (e.g. `'inactive'`) → removed from `feed_ready_items`.

---

### `feed_ready_items` (public serving table)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `news_item_id` | bigint (FK) | References `news_items.id`, cascade delete |
| `title` | varchar | |
| `summary` | text | Nullable |
| `source` | varchar | Nullable |
| `url` | varchar | |
| `published_at` | timestamp | Nullable |
| `primary_category` | varchar | Nullable |
| `secondary_category` | varchar | Nullable |
| `location_label` | varchar | Nullable — human-readable place name |
| `lat` | decimal(10,7) | Nullable |
| `lng` | decimal(10,7) | Nullable |
| `precision_type` | varchar | Nullable — but EXCLUDED from public API responses |
| `distance_km` | decimal(8,2) | Nullable — populated only in nearby query results |
| `relevance_mode` | varchar | Nullable — but EXCLUDED from public API responses |
| `is_active` | boolean | Default `true` — controls visibility |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

---

## 3. Category Structure

### Primary Category (top-level)
The `slug` in category endpoints MUST be a `primary_category` value. Only three exist in current data:

| Value | Meaning |
|---|---|
| `sports` | Sports news |
| `property` | Property/real estate news |
| `transport` | Transport/traffic news |

**Rule: DO NOT use `secondary_category` as a category filter.** The API treats any slug as `primary_category`. Passing a secondary value (e.g. `badminton`) as the slug will return `[]`.

### Secondary Category (subtopic)
Subtopic of the primary:

| Secondary | Belongs To | Example |
|---|---|---|
| `badminton` | sports | Tournament news |
| `rental` | property | Rental listings |
| `traffic` | transport | Congestion, accidents |

---

## 4. Geo Fields Explained

| Field | Type | Description |
|---|---|---|
| `lat` | decimal(10,7) | Latitude, e.g. `3.1390000` |
| `lng` | decimal(10,7) | Longitude, e.g. `101.6933000` |
| `precision_type` | varchar | Controls whether item appears in nearby API |
| `relevance_mode` | varchar | Controls whether item appears in nearby API |
| `location_label` | varchar | Human-readable place name, e.g. "Federal Highway, Kuala Lumpur" |

### `precision_type` — Valid Values

| Value | In Nearby API? | Notes |
|---|---|---|
| `exact_area` | ✅ Included | |
| `approximate_area` | ✅ Included | |
| `state_center` | ❌ Excluded | |
| `region` | ❌ Excluded | |
| `country` | ❌ Excluded | |
| `national` | ❌ Excluded | |
| `unresolved` | ❌ Excluded | |

### `relevance_mode` — Valid Values

| Value | In Nearby API? | Notes |
|---|---|---|
| `location_only` | ✅ Included | |
| `hybrid` | ✅ Included | |
| `category_only` | ❌ Excluded | Would require category-based matching — not implemented |

### `distance_km`
- Returned ONLY in `/feed/nearby` and `/feed/filter` (when radius is used) responses
- Not stored in DB — computed on-the-fly using Haversine formula
- Format: float rounded to 2 decimal places, e.g. `0.03` ( kilometers)

---

## 5. Base API Response Format

### Success
```json
[
  {
    "id": 6,
    "title": "Traffic Jam on Federal Highway",
    "summary": "Heavy congestion reported both directions",
    "source": "FMT",
    "published_at": "2026-04-02T14:45:47.000000Z",
    "primary_category": "transport",
    "secondary_category": "traffic",
    "url": "https://example.com/federal-jam"
  }
]
```
- Always returns a JSON array (even if empty: `[]`)
- No envelope/wrapper — raw array
- `published_at` format: ISO 8601 with timezone

### Error (422 Validation)
```json
{
  "message": "When radius is provided, lat and lng are required.",
  "errors": {
    "geo": ["When radius is provided, lat and lng are required."]
  }
}
```

### Error (404)
```json
{
  "message": "The route api/login could not be found."
}
```

### Field Naming
- Always `snake_case` (e.g. `published_at`, `primary_category`, `distance_km`, `location_label`)

---

# Feed API Contract

## Rate Limiting
All feed endpoints are rate-limited: **60 requests per minute per IP** (`throttle:60,1` middleware).

---

## Endpoint 1: Default Feed

### `GET /api/feed/default`

**Description:** Returns the global feed, newest first.

**Request:** No parameters.

**curl:**
```bash
curl 'https://nearbypost.com/api/feed/default' \
  -H 'Accept: application/json'
```

**Response:** `200 OK`
```json
[
  {
    "id": 23,
    "title": "Expressway Traffic Congestion Expected During Holiday Weekend",
    "summary": "Authorities warn of heavy traffic on major expressways...",
    "source": "Traffic Alert",
    "published_at": "2026-04-02T15:24:50.000000Z",
    "primary_category": "transport",
    "secondary_category": "traffic",
    "url": "https://example.com/expressway-congestion"
  },
  {
    "id": 6,
    "title": "Traffic Jam on Federal Highway",
    "summary": "Heavy congestion reported both directions",
    "source": "FMT",
    "published_at": "2026-04-02T14:45:47.000000Z",
    "primary_category": "transport",
    "secondary_category": "traffic",
    "url": "https://example.com/federal-jam"
  }
]
```

**Rules:**
- Max 20 items
- Sorted by `published_at` DESC (newest first)
- Only `is_active = true` items
- Only these 8 fields returned: `id, title, summary, source, published_at, primary_category, secondary_category, url`
- **NEVER returns:** `lat`, `lng`, `precision_type`, `relevance_mode`, `distance_km`, `location_label`

---

## Endpoint 2: Category Feed

### `GET /api/feed/category/{slug}`

**Description:** Returns feed filtered by primary category only.

**Path Parameters:**

| Param | Type | Required | Description |
|---|---|---|---|
| `slug` | string | Yes | Must be a valid `primary_category`: `sports`, `property`, or `transport` |

**curl:**
```bash
# Example — sports category
curl 'https://nearbypost.com/api/feed/category/sports' \
  -H 'Accept: application/json'

# Example — property category
curl 'https://nearbypost.com/api/feed/category/property' \
  -H 'Accept: application/json'

# Example — transport category
curl 'https://nearbypost.com/api/feed/category/transport' \
  -H 'Accept: application/json'
```

**Response:** `200 OK` (empty array if no items in category)
```json
[
  {
    "id": 17,
    "title": "Local Badminton Tournament Draws Record Participants",
    "summary": "The annual city badminton championship saw over 200 players...",
    "source": "Sports Daily",
    "published_at": "2026-04-02T14:24:50.000000Z",
    "primary_category": "sports",
    "secondary_category": "badminton",
    "url": "https://example.com/badminton-tournament"
  }
]
```

**Rules:**
- Max 20 items
- Sorted by `published_at` DESC
- Only items where `primary_category = slug`
- Returns `[]` if no match — NOT an error
- Same 8-field restriction as default feed

**⚠️ Known Issue:** Passing an invalid slug (e.g. `badminton`) returns `[]` silently. The frontend should validate slugs against known categories (`sports`, `property`, `transport`) before calling.

---

## Endpoint 3: Nearby Feed

### `GET /api/feed/nearby`

**Description:** Returns items sorted by physical distance from a given point, filtered by radius.

**Query Parameters:**

| Param | Type | Required | Valid Range | Description |
|---|---|---|---|---|
| `lat` | float | Yes | `-90` to `90` | Latitude |
| `lng` | float | Yes | `-180` to `180` | Longitude |
| `radius` | float | Yes | `1` to `500` | Search radius in km |

**curl:**
```bash
curl 'https://nearbypost.com/api/feed/nearby?lat=3.139&lng=101.693&radius=5' \
  -H 'Accept: application/json'
```

**Response:** `200 OK`
```json
[
  {
    "id": 6,
    "title": "Traffic Jam on Federal Highway",
    "summary": "Heavy congestion reported both directions",
    "source": "FMT",
    "published_at": "2026-04-02 14:45:47",
    "primary_category": "transport",
    "secondary_category": "traffic",
    "url": "https://example.com/federal-jam",
    "location_label": "Federal Highway, Kuala Lumpur",
    "distance_km": 0.03
  }
]
```

**Note:** `published_at` format differs from other endpoints — it is returned as `"2026-04-02 14:45:47"` (no timezone, no ISO microseconds) instead of ISO 8601. Frontend should treat it as a string.

**Rules:**
- Max 20 items
- Sorted by `distance_km` ASC (nearest first)
- **Filters applied:**
  - `lat IS NOT NULL`
  - `lng IS NOT NULL`
  - `relevance_mode != 'category_only'` (includes `location_only` and `hybrid`)
  - `precision_type IN ('exact_area', 'approximate_area')`
  - `distance_km <= radius` (Haversine formula)
- **NEVER falls back** to default feed if no results — returns `[]`
- Returns exactly these 10 fields: `id, title, summary, source, published_at, primary_category, secondary_category, url, location_label, distance_km`
- **NEVER returns:** `lat`, `lng`, `precision_type`, `relevance_mode`

**Validation Errors (422):**
```json
// Missing lat
{ "message": "The lat field is required." }

// Missing lng
{ "message": "The lng field is required." }

// Invalid type
{ "message": "The lat field must be a number." }

// lat out of range
{ "message": "The lat field must be between -90 and 90." }
```

**Haversine Formula Used:**
```sql
6371.0 * acos(
  LEAST(1.0,
    cos(radians(:lat)) * cos(radians(lat)) * cos(radians(lng) - radians(:lng)) +
    sin(radians(:lat)) * sin(radians(lat))
  )
)
```

---

# Filter & Sync Contract

## Endpoint 4: Filter Feed

### `GET /api/feed/filter`

**Description:** Flexible filter endpoint. Has two modes: non-geo (category/time filter) and geo (radius search).

**Query Parameters:**

| Param | Type | Required | Description |
|---|---|---|---|
| `primary_category` | string | No | Filter by top-level category |
| `secondary_category` | string | No | Filter by subtopic |
| `time` | integer | No | Return items from last N hours only |
| `radius` | float | No | Geo radius in km — **requires `lat` AND `lng`** |
| `lat` | float | No | Latitude — **required when `radius` is provided** |
| `lng` | float | No | Longitude — **required when `radius` is provided** |

**⚠️ Rule: If `radius` is provided, `lat` AND `lng` are both required. Otherwise → HTTP 422.**

**curl — Non-Geo Mode:**
```bash
# Filter by primary category
curl 'https://nearbypost.com/api/feed/filter?primary_category=sports' \
  -H 'Accept: application/json'

# Filter by primary + secondary
curl 'https://nearbypost.com/api/feed/filter?primary_category=sports&secondary_category=badminton' \
  -H 'Accept: application/json'

# Filter by category + time
curl 'https://nearbypost.com/api/feed/filter?primary_category=transport&time=24' \
  -H 'Accept: application/json'
```

**Response (non-geo):** `200 OK`
```json
[
  {
    "id": 17,
    "title": "Local Badminton Tournament Draws Record Participants",
    "summary": "...",
    "source": "Sports Daily",
    "published_at": "2026-04-02T14:24:50.000000Z",
    "primary_category": "sports",
    "secondary_category": "badminton",
    "url": "https://example.com/badminton-tournament"
  }
]
```
- Same 8 fields as default feed
- No `location_label` or `distance_km`

**curl — Geo Mode (with radius):**
```bash
curl 'https://nearbypost.com/api/feed/filter?radius=5&lat=3.139&lng=101.693' \
  -H 'Accept: application/json'
```

**Response (geo mode):** `200 OK`
```json
[
  {
    "id": 6,
    "title": "Traffic Jam on Federal Highway",
    "summary": "Heavy congestion reported both directions",
    "source": "FMT",
    "published_at": "2026-04-02 14:45:47",
    "primary_category": "transport",
    "secondary_category": "traffic",
    "url": "https://example.com/federal-jam",
    "location_label": "Federal Highway, Kuala Lumpur",
    "distance_km": 0.03
  }
]
```
- Same fields as nearby endpoint
- Geo filters apply (same exclusion rules as nearby)
- Sorted by `distance_km` ASC

**Validation Error (422):**
```json
{
  "message": "When radius is provided, lat and lng are required.",
  "errors": {
    "geo": ["When radius is provided, lat and lng are required."]
  }
}
```

**Filter Combination Rules:**
- All non-geo filters are combined with AND logic
- If `radius` is provided, geo filters activate (same rules as nearby)
- Max 20 results

---

## Sync Mechanism: `news_items` → `feed_ready_items`

### Implementation
Laravel `Observer` pattern. File: `/var/www/nearbypost/app/Observers/NewsItemObserver.php`

### How Sync Works

| Event on `news_items` | Action |
|---|---|
| `created` | Syncs item to `feed_ready_items` |
| `updated` | Syncs updated data to `feed_ready_items` |
| `deleted` | Removes corresponding row from `feed_ready_items` |
| `restored` | Syncs item back to `feed_ready_items` |
| `forceDeleted` | Removes from `feed_ready_items` |

### Sync Logic (`syncToFeed` method)
```php
if ($newsItem->status !== 'active') {
    // Remove from feed if not active
    FeedReadyItem::where('news_item_id', $newsItem->id)->delete();
    return;
}
// Insert or update
FeedReadyItem::updateOrCreate(
    ['news_item_id' => $newsItem->id],
    [
        'title'             => $newsItem->title,
        'summary'           => $newsItem->summary,
        'source'            => $newsItem->source,
        'url'               => $newsItem->url,
        'published_at'      => $newsItem->published_at,
        'primary_category'  => $newsItem->primary_category,
        'secondary_category'=> $newsItem->secondary_category,
        'location_label'    => $newsItem->location_label,
        'lat'               => $newsItem->lat,
        'lng'               => $newsItem->lng,
        'precision_type'    => $newsItem->precision_type,
        'relevance_mode'    => $newsItem->relevance_mode,
        'is_active'         => true,
    ]
);
```

### Rules
- Only `status = 'active'` items appear in `feed_ready_items`
- `status` anything other than `'active'` → item is removed
- Observer is registered in `AppServiceProvider::boot()`
- `is_active` in `feed_ready_items` is always set to `true` during sync (no way to set it to `false` via sync — it reflects `news_items.status`)

---

# Admin API Contract

## ⚠️ NOT IMPLEMENTED

There is **NO Laravel API for admin CRUD operations**.

### What EXISTS:

**Admin Panel:** Static HTML file at `/admin.html` (served by nginx, not Laravel)
- Access: `https://nearbypost.com/admin.html`
- Authentication: **HTTP Basic Auth** (nginx)
  - User: `admin`
  - Password: `nearbypost2026`
- The admin panel makes API calls to `/api/...` endpoints for feed data

**No Laravel Routes for:**
- Creating news items
- Updating news items
- Deleting news items
- Listing news items
- Admin authentication via API

### Implication for Frontend

Ronak (frontend team) should NOT try to integrate with a non-existent admin API. The current admin panel is:
1. A static HTML/JS page served by nginx
2. Using HTTP Basic Auth (not JWT or session auth)
3. Likely making calls that are not yet implemented

**Action Required:** If admin CRUD is needed, it must be implemented as a new Laravel feature.

---

# Issues & Risks

## 1. Admin CRUD — Not Implemented
**Severity: HIGH**

There is no way to create, update, or delete news items via API. The admin panel at `/admin.html` is a static page — its ability to manage data is unclear and unverified. If the admin panel depends on backend API routes that don't exist, content management is broken.

**Action:** Implement Laravel admin API endpoints for news_items CRUD, or clarify how the existing admin.html is supposed to work.

---

## 2. Category Slugs Are Not Validated
**Severity: MEDIUM**

`GET /api/feed/category/{slug}` accepts any string and returns `[]` for unknown slugs — no error, no indication of invalid category. Frontend could silently get empty results if slug is misspelled.

**Action:** Consider adding validation to return 422 for invalid categories, or document that frontend must validate against known categories.

---

## 3. `published_at` Format Inconsistency
**Severity: LOW**

`/feed/default` and `/feed/category` return `published_at` as ISO 8601 with timezone:
```
"2026-04-02T14:45:47.000000Z"
```

But `/feed/nearby` and `/feed/filter` (geo mode) return `published_at` as a plain string:
```
"2026-04-02 14:45:47"
```

**Action:** Frontend should handle both string formats. Or backend should be fixed to be consistent.

---

## 4. No Pagination
**Severity: MEDIUM**

All endpoints return max 20 items with no pagination mechanism (`page`, `offset`, `cursor`, etc.). If more than 20 items exist for a category or filter, they are silently truncated.

**Action:** Implement pagination (e.g. `?page=2` or `?offset=20`) if datasets grow beyond 20 items.

---

## 5. `status` Field Is a String, Not Boolean
**Severity: LOW**

`news_items.status` is stored as a string (`'active'`, `'inactive'`, etc.) rather than a boolean. The sync logic checks `status !== 'active'`. Any value other than exactly `'active'` (including null, empty string, `'pending'`) will cause the item to be removed from `feed_ready_items`. This is fragile — a typo like `'Active'` or `'active '` would silently break sync.

**Action:** Document the exact string value `'active'` that must be used, or refactor to boolean.

---

## 6. No API Authentication on Public Feed Endpoints
**Severity: LOW**

All `/api/feed/*` endpoints are publicly accessible with no authentication. This is likely intentional for a public news feed, but rate limiting (60 req/min) is the only protection against abuse.

---

## 7. Foreign Key Cascade Delete Risk
**Severity: MEDIUM**

`feed_ready_items.news_item_id` has `cascadeOnDelete()` — deleting a `news_item` will automatically delete its corresponding `feed_ready_item`. This is correct behavior for the sync model, but any direct delete on `news_items` (bypassing the observer) will cause orphaned or inconsistent data.

---

## 8. Seeder Depends on Existing `news_items`
**Severity: LOW**

The `FeedReadyItemSeeder` first syncs from existing `news_items` records, then adds manual seed records only if count < 15. This means seed data depends on pre-existing news_items. Fresh deployments with no news_items would get fewer than 15 feed records from seeding.

**Action:** The 15+ manual fallback records ensure minimum seed count, but they may not appear if `news_items` already has >= 15 records (the seeder only adds manual records as fallback).

---

## 9. No HTTPS Certificate Validation on Internal curl Calls
**Severity: LOW**

The server uses a self-signed SSL certificate for `https://nearbypost.com`. Internal API calls from nginx to Laravel use `http://127.0.0.1:8000`. This is fine for internal routing but means the public HTTPS endpoint relies on a self-signed cert — browsers will show a security warning until a proper certificate is installed.

---

*Document prepared by cylyl-labs AI team, based on live server inspection and API testing. Server: 187.127.97.175, project: /var/www/nearbypost*

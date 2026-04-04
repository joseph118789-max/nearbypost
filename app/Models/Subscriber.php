<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'status',
        'group_id',
    ];

    protected $casts = [
        'group_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'preferences' => 'array',
        'preferred_categories' => 'array',
        'alert_radius_km' => 'float',
        'location_lat' => 'float',
        'location_lng' => 'float',
        'notify_email' => 'boolean',
        'notify_webhook' => 'boolean',
        'notify_in_app' => 'boolean',
        'max_notifications_per_hour' => 'integer',
    ];

    // Valid notification frequencies
    public const FREQUENCY_IMMEDIATE = 'immediate';
    public const FREQUENCY_HOURLY = 'hourly';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';

    public const VALID_FREQUENCIES = [
        self::FREQUENCY_IMMEDIATE,
        self::FREQUENCY_HOURLY,
        self::FREQUENCY_DAILY,
        self::FREQUENCY_WEEKLY,
    ];

    // Frontend-friendly response transformation
    public function toFrontendArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'status' => $this->status,
            'group_id' => $this->group_id,
            'group' => $this->group ? [
                'id' => $this->group->id,
                'name' => $this->group->name,
                'is_active' => $this->group->is_active,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BroadcastGroup::class, 'group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // ========== Preference Management Methods ==========

    /**
     * Get all preferences as a structured array
     */
    public function getPreferences(): array
    {
        return [
            'preferred_categories' => $this->preferred_categories ?? [],
            'alert_radius_km' => (float) ($this->alert_radius_km ?? 10.00),
            'location_lat' => $this->location_lat ? (float) $this->location_lat : null,
            'location_lng' => $this->location_lng ? (float) $this->location_lng : null,
            'notification_frequency' => $this->notification_frequency ?? self::FREQUENCY_IMMEDIATE,
        ];
    }

    /**
     * Update preferences with merge logic
     * Only updates fields that are provided (partial update)
     */
    public function updatePreferences(array $preferences): self
    {
        $allowedFields = [
            'preferred_categories',
            'alert_radius_km',
            'location_lat',
            'location_lng',
            'notification_frequency',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $preferences)) {
                $this->$field = $preferences[$field];
            }
        }

        // Validate notification_frequency if provided
        if (isset($preferences['notification_frequency'])) {
            if (!in_array($preferences['notification_frequency'], self::VALID_FREQUENCIES)) {
                throw new \InvalidArgumentException(
                    "Invalid notification频率. Valid: " . implode(', ', self::VALID_FREQUENCIES)
                );
            }
        }

        $this->save();
        return $this;
    }

    /**
     * Check if subscriber has location set
     */
    public function hasLocation(): bool
    {
        return $this->location_lat !== null && $this->location_lng !== null;
    }

    /**
     * Check if subscriber has any preferences set
     */
    public function hasPreferences(): bool
    {
        return !empty($this->preferred_categories) 
            || $this->alert_radius_km != 10.00
            || $this->hasLocation()
            || $this->notification_frequency !== self::FREQUENCY_IMMEDIATE;
    }

    /**
     * Check if a news item matches subscriber's preferences
     */
    public function matchesPreferences(NewsItem $newsItem): bool
    {
        // Check category match if categories are set
        if (!empty($this->preferred_categories)) {
            if ($newsItem->category && !in_array($newsItem->category, $this->preferred_categories)) {
                return false;
            }
        }

        // Check location proximity if location is set
        if ($this->hasLocation() && $newsItem->latitude && $newsItem->longitude) {
            $distance = $this->calculateDistance(
                $this->location_lat,
                $this->location_lng,
                $newsItem->latitude,
                $newsItem->longitude
            );
            if ($distance > $this->alert_radius_km) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate distance between two points using Haversine formula
     */
    public static function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1Rad) * cos($lat2Rad) *
            sin($deltaLng / 2) * sin($deltaLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
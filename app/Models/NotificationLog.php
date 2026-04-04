<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WEBHOOK = 'webhook';
    public const CHANNEL_IN_APP = 'in_app';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RATE_LIMITED = 'rate_limited';

    protected $fillable = [
        'subscriber_id',
        'news_item_id',
        'channel',
        'status',
        'recipient',
        'payload',
        'response',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'subscriber_id' => 'integer',
        'news_item_id' => 'integer',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class, 'news_item_id');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeRecent($query, int $hours = 1)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
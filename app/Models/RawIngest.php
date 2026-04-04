<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawIngest extends Model
{
    protected $table = 'raw_ingest';

    protected $fillable = [
        'source',
        'raw_json_payload',
        'received_at',
        'processing_status',
        'error_message',
        'news_item_id',
    ];

    protected $casts = [
        'raw_json_payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}

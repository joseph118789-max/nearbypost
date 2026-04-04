<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'news_item_id',
        'reason',
        'note',
        'status',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'news_item_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Frontend-friendly response
    public function toFrontendArray(): array
    {
        return [
            'id' => $this->id,
            'news_item_id' => $this->news_item_id,
            'reason' => $this->reason,
            'note' => $this->note,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\NewsItem::class, 'news_item_id');
    }
}
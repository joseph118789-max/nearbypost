<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsItem extends Model
{
    protected $table = 'news_items';

    protected $fillable = [
        'title',
        'summary',
        'source',
        'url',
        'status',
        'published_at',
        'primary_category',
        'secondary_category',
        'main_place_text',
        'lat',
        'lng',
        'precision_type',
        'relevance_mode',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
    ];
}

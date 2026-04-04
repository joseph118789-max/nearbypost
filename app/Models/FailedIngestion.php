<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FailedIngestion extends Model
{
    protected $table = 'failed_ingestions';

    protected $fillable = [
        'source',
        'url',
        'title',
        'raw_payload',
        'failure_reason',
        'retry_count',
        'failed_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'failed_at' => 'datetime',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawIngest extends Model
{
    protected $table = 'raw_ingest';

    protected $fillable = [
        'source',
        'raw_json_payload',
        'received_at',
        'processing_status',
        'error_message',
    ];

    protected $casts = [
        'raw_json_payload' => 'array',
        'received_at' => 'datetime',
    ];
}
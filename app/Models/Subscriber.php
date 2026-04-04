<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'status' => 'string',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(BroadcastGroup::class, 'group_id');
    }
}

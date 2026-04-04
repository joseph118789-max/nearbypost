<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscriber extends Model
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'user_code',
        'mobile',
        'wa_group',
        'interest_sub_cat',
        'status',
        'location_name',
        'join_date',
        'name',
        'email',
        'password',
    ];

    protected $casts = [
        'join_date' => 'datetime',
    ];

    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class, 'subscriber_id');
    }
}

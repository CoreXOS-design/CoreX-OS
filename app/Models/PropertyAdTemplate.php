<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyAdTemplate extends Model
{
    protected $fillable = ['user_id', 'name', 'layout_json', 'is_global'];

    protected $casts = [
        'layout_json' => 'array',
        'is_global'   => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyMarketingActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'property_id', 'activity_type', 'activity_data',
        'occurred_at', 'logged_by_user_id', 'internal_only',
    ];

    protected $casts = [
        'activity_data' => 'array',
        'occurred_at' => 'datetime',
        'internal_only' => 'boolean',
    ];

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }

    public function scopeSellerVisible($query) { return $query->where('internal_only', false); }
}

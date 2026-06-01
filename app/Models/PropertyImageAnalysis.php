<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyImageAnalysis extends Model
{
    use BelongsToAgency;

    protected $table = 'property_image_analyses';

    protected $fillable = [
        'agency_id', 'property_id', 'image_path', 'status',
        'detected_features', 'detected_spaces', 'raw_response',
        'cost_usd', 'error', 'processed_at',
    ];

    protected $casts = [
        'detected_features' => 'array',
        'detected_spaces'   => 'array',
        'raw_response'      => 'array',
        'cost_usd'          => 'float',
        'processed_at'      => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}

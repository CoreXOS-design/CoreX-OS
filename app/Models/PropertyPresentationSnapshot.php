<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class PropertyPresentationSnapshot extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'property_id', 'presentation_id', 'generated_at', 'generated_by_user_id',
        'market_data_snapshot', 'recommended_price_at_time', 'days_on_market_at_time',
        'is_dynamic', 'notes',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'market_data_snapshot' => 'array',
        'recommended_price_at_time' => 'decimal:2',
        'is_dynamic' => 'boolean',
    ];

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
}

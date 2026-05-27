<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class BuyerPropertyView extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'contact_id', 'property_id', 'last_viewed_at',
        'view_count', 'most_recent_feedback_id',
    ];

    protected $casts = ['last_viewed_at' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
}

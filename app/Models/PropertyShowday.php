<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class PropertyShowday extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'property_id',
        'start_date',
        'end_date',
        'description',
        'active',
        'synced_to_pp',
    ];

    protected $casts = [
        'start_date'    => 'datetime',
        'end_date'      => 'datetime',
        'active'        => 'boolean',
        'synced_to_pp'  => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}

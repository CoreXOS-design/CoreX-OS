<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresentationLink extends Model
{
    // type values: property24 | lightstone | other
    protected $fillable = [
        'presentation_id',
        'type',
        'url',
        'notes',
        'created_by_user_id',
        'asking_price_inc',
        'beds',
        'baths',
        'floor_area_m2',
        'erf_m2',
        'property_type',
        'suburb',
    ];

    protected $casts = [
        'asking_price_inc' => 'integer',
        'beds'             => 'integer',
        'baths'            => 'integer',
        'floor_area_m2'    => 'integer',
        'erf_m2'           => 'integer',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }
}

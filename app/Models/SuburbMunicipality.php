<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The p24_suburb_id -> legal municipality mapping the suburb report joins
 * against. GLOBAL — not agency-scoped. See the creating migration's doc
 * comment for why this table exists and what it deliberately does not use
 * (geocoding_cache.municipality_name — confirmed wrong for this purpose).
 */
class SuburbMunicipality extends Model
{
    public const CONFIDENCE_CONFIRMED    = 'confirmed';
    public const CONFIDENCE_NEEDS_REVIEW = 'needs_review';

    protected $table = 'suburb_municipalities';

    protected $fillable = [
        'p24_suburb_id',
        'suburb_name',
        'municipality',
        'confidence',
        'source',
    ];

    public function suburb()
    {
        return $this->belongsTo(P24Suburb::class, 'p24_suburb_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PresentationActiveListing extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'presentation_id',
        'source_upload_id',
        'source_snapshot_id',
        'listing_date',
        'list_price_inc',
        'suburb',
        'property_type',
        'beds',
        'baths',
        'size_m2',
        'status',
        'raw_row_json',
        'parser_version',
        'extraction_method',
        'external_key',
        'fingerprint',
        'first_seen_at',
        'last_seen_at',
        'is_active',
        'source_rank',
        'merge_confidence',
        'data_quality_score',
        'conflict_flags_json',
    ];

    protected $casts = [
        'listing_date'   => 'date',
        'list_price_inc' => 'integer',
        'beds'           => 'integer',
        'baths'          => 'integer',
        'size_m2'        => 'integer',
        'first_seen_at'  => 'datetime',
        'last_seen_at'   => 'datetime',
        'is_active'          => 'boolean',
        'source_rank'        => 'integer',
        'merge_confidence'   => 'integer',
        'data_quality_score' => 'integer',
        'conflict_flags_json' => 'array',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function sourceUpload()
    {
        return $this->belongsTo(PresentationUpload::class, 'source_upload_id');
    }

    public function priceHistory()
    {
        return $this->hasMany(PresentationListingPriceHistory::class, 'active_listing_id')
                    ->orderBy('captured_at');
    }
}

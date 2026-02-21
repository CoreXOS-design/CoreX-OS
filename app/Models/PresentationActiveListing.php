<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresentationActiveListing extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'presentation_id',
        'source_upload_id',
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
    ];

    protected $casts = [
        'listing_date'   => 'date',
        'list_price_inc' => 'integer',
        'beds'           => 'integer',
        'baths'          => 'integer',
        'size_m2'        => 'integer',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function sourceUpload()
    {
        return $this->belongsTo(PresentationUpload::class, 'source_upload_id');
    }
}

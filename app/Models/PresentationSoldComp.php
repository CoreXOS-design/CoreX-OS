<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PresentationSoldComp extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'presentation_id',
        'source_upload_id',
        'sold_date',
        'sold_price_inc',
        'suburb',
        'property_type',
        'beds',
        'baths',
        'size_m2',
        'listed_date',
        'raw_row_json',
        'parser_version',
    ];

    protected $casts = [
        'sold_date'      => 'date',
        'listed_date'    => 'date',
        'sold_price_inc' => 'integer',
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

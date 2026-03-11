<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PresentationSection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'presentation_id',
        'section_key',
        'data_json',
        'sort_order',
    ];

    protected $casts = [
        'data_json'   => 'array',
        'sort_order'  => 'integer',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }
}

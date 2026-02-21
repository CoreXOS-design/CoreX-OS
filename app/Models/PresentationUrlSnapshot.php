<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresentationUrlSnapshot extends Model
{
    protected $fillable = [
        'presentation_id',
        'url',
        'snapshot_html',
        'source_type',
        'http_status',
        'content_hash',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }
}

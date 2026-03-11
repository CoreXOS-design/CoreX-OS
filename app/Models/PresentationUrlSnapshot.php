<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PresentationUrlSnapshot extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'presentation_id',
        'url',
        'final_url',
        'snapshot_html',
        'source_type',
        'http_status',
        'content_type',
        'content_bytes',
        'blocked_reason',
        'timed_out',
        'content_hash',
        'response_headers_json',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at'            => 'datetime',
        'timed_out'             => 'boolean',
        'response_headers_json' => 'array',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }
}

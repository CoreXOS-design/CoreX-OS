<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalCapture extends Model
{
    protected $fillable = [
        'user_id',
        'presentation_id',
        'source_site',
        'page_type',
        'source_url',
        'final_url',
        'page_title',
        'captured_at',
        'extractor_version',
        'dom_hash_sha256',
        'html_bytes',
        'raw_html_path',
        'screenshot_path',
        'parse_status',
        'extracted_fields_json',
        'jsonld_json',
        'found_image_urls_json',
    ];

    protected $casts = [
        'captured_at'            => 'datetime',
        'extracted_fields_json'  => 'array',
        'jsonld_json'            => 'array',
        'found_image_urls_json'  => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }
}

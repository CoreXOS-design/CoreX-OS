<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalCapture extends Model
{
    use HasFactory, SoftDeletes, BelongsToAgency;
    protected $fillable = [
        'agency_id',
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

    protected static function booted(): void
    {
        // A capture belongs to the agency of the user who made it (BelongsToAgency has already stamped a signed-in user's agency).
        static::creating(function (self $capture) {
            if (empty($capture->agency_id) && $capture->user_id) {
                $capture->agency_id = \App\Models\User::withoutGlobalScopes()->whereKey($capture->user_id)->value('agency_id');
            }
        });
    }

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

    public function listingObservations()
    {
        return $this->hasMany(PortalListingObservation::class, 'capture_id');
    }

    /**
     * Count price changes detected by this capture.
     */
    public function priceChangeCount(): int
    {
        return $this->listingObservations()
            ->whereNotNull('changed_fields_json')
            ->whereRaw("json_extract(changed_fields_json, '$.price') IS NOT NULL")
            ->count();
    }
}

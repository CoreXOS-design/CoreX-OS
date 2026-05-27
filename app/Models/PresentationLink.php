<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PresentationLink extends Model
{
    use BelongsToAgency, SoftDeletes;


    // type values: property24 | lightstone | active_listing | competitor_listing | market_article | other
    protected $fillable = [
        'agency_id',
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
        'extraction_status',
        'extracted_json',
        'extraction_error',
        'extracted_at',
        'override_json',
        'override_by_user_id',
        'override_at',
        'portal_capture_id',
    ];

    protected $casts = [
        'asking_price_inc' => 'integer',
        'beds'             => 'integer',
        'baths'            => 'integer',
        'floor_area_m2'    => 'integer',
        'erf_m2'           => 'integer',
        'extracted_json'   => 'array',
        'override_json'    => 'array',
        'extracted_at'     => 'datetime',
        'override_at'      => 'datetime',
    ];

    /**
     * Returns verified data: override values if set, else extracted values, else null.
     * Handles legacy string JSON gracefully.
     */
    public function getVerifiedData(): ?array
    {
        $override = $this->safeArray($this->override_json);
        if (!empty($override)) {
            return $override;
        }

        $extracted = $this->safeArray($this->extracted_json);
        if (!empty($extracted)) {
            return $extracted;
        }

        return null;
    }

    /**
     * Safely coerce a value to array — handles string JSON and double-encoded strings.
     */
    private function safeArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            // Handle double-encoding: json_decode returns a string that is itself JSON
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /**
     * Whether this link has been overridden by a user.
     */
    public function isOverridden(): bool
    {
        return !empty($this->override_json);
    }

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function portalCapture()
    {
        return $this->belongsTo(PortalCapture::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresentationUpload extends Model
{
    protected $fillable = [
        'presentation_id',
        'uploaded_by_user_id',
        'type',
        'original_filename',
        'storage_path',
        'file_slug',
        'content_hash',
        'text_extracted',
        'extraction_json',
        'extraction_status',
        'extracted_at',
        'extraction_error',
        'override_json',
        'override_by_user_id',
        'override_at',
    ];

    protected $casts = [
        'extraction_json' => 'array',
        'override_json'   => 'array',
        'extracted_at'    => 'datetime',
        'override_at'     => 'datetime',
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

        $extracted = $this->safeArray($this->extraction_json);
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
     * Whether this upload has been overridden by a user.
     */
    public function isOverridden(): bool
    {
        return !empty($this->override_json);
    }

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function soldComps()
    {
        return $this->hasMany(PresentationSoldComp::class, 'source_upload_id');
    }

    public function activeListings()
    {
        return $this->hasMany(PresentationActiveListing::class, 'source_upload_id');
    }
}

<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Communication attachment (AT-32). Content-hash dedup — identical files share
 * one stored object.
 */
class CommunicationAttachment extends Model
{
    use SoftDeletes, BelongsToAgency;

    // AT-148 — media lifecycle for the WAHA server-session transport, where media
    // is a URL fetched from WAHA rather than inline base64.
    const MEDIA_STORED  = 'stored';   // bytes on the volume at storage_path
    const MEDIA_PENDING = 'pending';  // download not done / failed; remote_ref holds the WAHA url
    const MEDIA_FAILED  = 'failed';   // permanent give-up

    protected $fillable = [
        'agency_id', 'communication_id', 'filename', 'mime',
        'size_bytes', 'content_hash', 'storage_path',
        'media_status', 'remote_ref', 'duration_seconds',
    ];

    protected $casts = [
        'size_bytes'       => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    /** A voice note / audio attachment (renders an inline player in the archive). */
    public function isAudio(): bool
    {
        return is_string($this->mime) && str_starts_with(strtolower($this->mime), 'audio/');
    }

    /** Bytes are on the volume and downloadable. */
    public function isPlayable(): bool
    {
        return $this->media_status === self::MEDIA_STORED && ! empty($this->storage_path);
    }
}

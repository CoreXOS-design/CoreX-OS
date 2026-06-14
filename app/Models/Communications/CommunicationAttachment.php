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

    protected $fillable = [
        'agency_id', 'communication_id', 'filename', 'mime',
        'size_bytes', 'content_hash', 'storage_path',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }
}

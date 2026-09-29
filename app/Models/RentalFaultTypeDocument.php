<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rentals-faults-work-orders.md §2 — an image/PDF/video-link/
 * document attached to a fault type's first-aid content.
 */
class RentalFaultTypeDocument extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const TYPE_IMAGE = 'image';
    public const TYPE_PDF = 'pdf';
    public const TYPE_VIDEO_LINK = 'video_link';
    public const TYPE_DOCUMENT = 'document';

    protected $fillable = [
        'agency_id',
        'rental_fault_type_id',
        'document_type',
        'storage_path',
        'external_url',
        'caption',
        'sort_order',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function faultType(): BelongsTo
    {
        return $this->belongsTo(RentalFaultType::class, 'rental_fault_type_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

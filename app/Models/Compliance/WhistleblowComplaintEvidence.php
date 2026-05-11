<?php

namespace App\Models\Compliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhistleblowComplaintEvidence extends Model
{
    protected $table = 'whistleblow_complaint_evidence';

    protected $fillable = [
        'complaint_id',
        'evidence_type',
        'file_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'description',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(WhistleblowComplaint::class, 'complaint_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

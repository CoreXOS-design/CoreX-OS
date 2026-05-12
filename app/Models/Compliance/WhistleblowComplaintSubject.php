<?php

namespace App\Models\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhistleblowComplaintSubject extends Model
{
    protected $table = 'whistleblow_complaint_subjects';

    protected $fillable = [
        'complaint_id',
        'agency_name',
        'practitioner_name',
        'portal_url',
        'portal_source',
        'portal_listing_ref',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(WhistleblowComplaint::class, 'complaint_id');
    }
}

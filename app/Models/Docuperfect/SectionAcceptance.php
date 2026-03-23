<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;

class SectionAcceptance extends Model
{
    protected $table = 'section_acceptances';

    protected $fillable = [
        'signature_request_id',
        'section_index',
        'section_label',
        'accepted',
        'rejected',
        'rejection_reason',
        'initialled_at',
        'initial_image',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'rejected' => 'boolean',
        'initialled_at' => 'datetime',
    ];

    public function signingRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}

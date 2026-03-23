<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;

class AmendmentAcceptance extends Model
{
    protected $table = 'amendment_acceptances';

    protected $fillable = [
        'amendment_id',
        'signature_request_id',
        'accepted',
        'rejected',
        'rejection_reason',
        'initial_image',
    ];

    protected $casts = [
        'accepted' => 'boolean',
        'rejected' => 'boolean',
    ];

    // --- Relationships ---

    public function amendment()
    {
        return $this->belongsTo(DocumentAmendment::class, 'amendment_id');
    }

    public function signingRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    // --- Helpers ---

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function isPending(): bool
    {
        return !$this->accepted && !$this->rejected;
    }
}

<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A signer adopting their signature and initial once, agreeing to the stored wording (esign-signing-modes.md §5).
 * Append-only evidence: never edited, never deleted — the only permitted change is stamping the row superseded when
 * the signer adopts again (a new generation). Same pattern as SignaturePlaceGeneration.
 */
class SignatureAdoption extends Model
{
    protected $table = 'signature_adoptions';

    protected $fillable = [
        'signature_request_id', 'signature_template_id', 'generation', 'mode', 'signer_name', 'signer_email',
        'signature_image', 'initial_image', 'signature_image_hash', 'initial_image_hash',
        'disclaimer_version', 'disclaimer_text', 'ip_address', 'user_agent', 'adopted_at',
        'superseded_at', 'superseded_by_id',
    ];

    protected $casts = [
        'generation' => 'integer',
        'disclaimer_version' => 'integer',
        'adopted_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if (array_diff(array_keys($row->getDirty()), ['superseded_at', 'superseded_by_id', 'updated_at']) !== []) {
                throw new LogicException('A signature adoption is evidence and cannot be edited — adopt again to record a new generation.');
            }
        });

        static::deleting(function () {
            throw new LogicException('A signature adoption is evidence and cannot be deleted.');
        });
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_at');
    }

    public function signingRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}

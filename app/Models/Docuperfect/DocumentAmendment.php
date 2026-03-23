<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentAmendment extends Model
{
    use SoftDeletes;

    protected $table = 'document_amendments';

    protected $fillable = [
        'document_id',
        'signature_template_id',
        'amended_by_request_id',
        'amendment_type',
        'section_reference',
        'original_text',
        'new_text',
        'document_version_before',
        'document_version_after',
        'document_hash_before',
        'document_hash_after',
        'status',
    ];

    const TYPE_ADDITION = 'addition';
    const TYPE_STRIKEOUT = 'strikeout';
    const TYPE_MODIFICATION = 'modification';

    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

    // --- Relationships ---

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function amendedByRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'amended_by_request_id');
    }

    public function acceptances()
    {
        return $this->hasMany(AmendmentAcceptance::class, 'amendment_id');
    }

    // --- Scopes ---

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForTemplate($query, int $templateId)
    {
        return $query->where('signature_template_id', $templateId);
    }

    // --- Helpers ---

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if all required parties have accepted this amendment.
     */
    public function isFullyAccepted(): bool
    {
        return $this->acceptances()->where('accepted', false)->where('rejected', false)->doesntExist()
            && $this->acceptances()->where('accepted', true)->exists();
    }
}

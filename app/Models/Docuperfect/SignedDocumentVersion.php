<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SignedDocumentVersion extends Model
{
    protected $table = 'signed_document_versions';

    protected $fillable = [
        'document_id',
        'signature_request_id',
        'kind',
        'version_number',
        'file_path',
        'file_type',
        'uploaded_by_name',
        'uploaded_at',
        'ip_address',
        'agent_approved',
        'agent_approved_at',
        'approved_by',
        'notes',
    ];

    /** A recipient-uploaded optional supporting document (not a signed version). */
    public const KIND_SUPPORTING = 'supporting';

    /** True when this row is an optional supporting document, not a signed version. */
    public function isSupporting(): bool
    {
        return $this->kind === self::KIND_SUPPORTING;
    }

    /** Scope: only optional supporting-document rows. */
    public function scopeSupporting($query)
    {
        return $query->where('kind', self::KIND_SUPPORTING);
    }

    protected $casts = [
        'agent_approved' => 'boolean',
        'uploaded_at' => 'datetime',
        'agent_approved_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function signingRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the next version number for a document.
     */
    public static function nextVersion(int $documentId): int
    {
        return (static::where('document_id', $documentId)->max('version_number') ?? 0) + 1;
    }
}

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

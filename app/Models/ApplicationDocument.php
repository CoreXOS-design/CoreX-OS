<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationDocument extends Model
{
    protected $fillable = [
        'application_id',
        'document_type',
        'file_path',
        'file_name',
        'rejection_reason',
    ];
    // INTENTIONALLY EXCLUDED: status (set by verify flow), verified_by, verified_at.

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public const TYPE_LABELS = [
        'id_copy' => 'ID Copy',
        'ffc_certificate' => 'FFC Certificate',
        'qualifications' => 'Qualifications',
        'pi_insurance' => 'PI Insurance',
        'tax_clearance' => 'Tax Clearance',
        'proof_of_address' => 'Proof of Address',
        'cv' => 'CV / Resume',
        'other' => 'Other',
    ];

    // ── Relationships ──

    public function application()
    {
        return $this->belongsTo(AgentApplication::class, 'application_id');
    }

    public function verifiedByUser()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ── Accessors ──

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->document_type] ?? ucfirst(str_replace('_', ' ', $this->document_type));
    }
}

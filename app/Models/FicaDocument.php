<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class FicaDocument extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'fica_submission_id',
        'document_type',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'status',
        'rejection_reason',
        'uploaded_at',
        'reviewed_at',
        'uploaded_by',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'file_size'   => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FicaSubmission::class, 'fica_submission_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }

    public function getDocumentTypeLabelAttribute(): string
    {
        return match ($this->document_type) {
            'fica_form'              => 'FICA Form (Wet-Ink)',
            'id_copy'                => 'ID Copy',
            'proof_of_address'       => 'Proof of Address',
            'authority'              => 'Authority Letter',
            'bank_statement'         => 'Bank Statement',
            'tax_clearance'          => 'Tax Clearance',
            'company_registration'   => 'Company Registration',
            'trust_deed'             => 'Trust Deed',
            'other'                  => 'Other',
            default                  => ucfirst(str_replace('_', ' ', $this->document_type)),
        };
    }
}

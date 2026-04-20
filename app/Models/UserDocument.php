<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserDocument extends Model
{
    use HasFactory, SoftDeletes, BelongsToAgency;

    // ── Document type constants ──

    public const DOCUMENT_TYPE_FFC_CERTIFICATE  = 'ffc_certificate';
    public const DOCUMENT_TYPE_ID_COPY          = 'id_copy';
    public const DOCUMENT_TYPE_PI_INSURANCE     = 'pi_insurance';
    public const DOCUMENT_TYPE_TAX_CLEARANCE    = 'tax_clearance';
    public const DOCUMENT_TYPE_PROFILE_PHOTO    = 'profile_photo';
    public const DOCUMENT_TYPE_QUALIFICATION    = 'qualification';
    public const DOCUMENT_TYPE_PROOF_OF_ADDRESS = 'proof_of_address';
    public const DOCUMENT_TYPE_BANK_CONFIRMATION = 'bank_confirmation';
    public const DOCUMENT_TYPE_OTHER              = 'other';
    public const DOCUMENT_TYPE_POLICE_CLEARANCE   = 'police_clearance';
    public const DOCUMENT_TYPE_CREDIT_CHECK_REPORT = 'credit_check_report';
    public const DOCUMENT_TYPE_REFERENCE_LETTER    = 'reference_letter';

    public static array $documentTypeLabels = [
        'ffc_certificate'      => 'FFC Certificate',
        'id_copy'              => 'ID Copy',
        'pi_insurance'         => 'PI Insurance',
        'tax_clearance'        => 'Tax Clearance',
        'profile_photo'        => 'Profile Photo',
        'qualification'        => 'Qualification',
        'proof_of_address'     => 'Proof of Address',
        'bank_confirmation'    => 'Bank Confirmation',
        'police_clearance'     => 'Police Clearance',
        'credit_check_report'  => 'Credit Check Report',
        'reference_letter'     => 'Reference Letter',
        'other'                => 'Other',
    ];

    // ── Mass assignment ──

    protected $fillable = [
        'agency_id',
        'user_id',
        'document_type',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'status',
        'expiry_date',
        'verified_by',
        'verified_at',
        'rejected_reason',
        'rejected_by',
        'rejected_at',
        'uploaded_by',
        'uploaded_by_admin',
        'admin_upload_reason',
        'notes',
    ];

    protected $casts = [
        'expiry_date'  => 'date',
        'verified_at'  => 'datetime',
        'rejected_at'  => 'datetime',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Scopes ──

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    public function scopeExpiring($query, int $days = 60)
    {
        return $query->where('status', 'verified')
                     ->whereNotNull('expiry_date')
                     ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'expired')
              ->orWhere(function ($q2) {
                  $q2->where('status', 'verified')
                     ->whereNotNull('expiry_date')
                     ->where('expiry_date', '<', now()->toDateString());
              });
        });
    }
}

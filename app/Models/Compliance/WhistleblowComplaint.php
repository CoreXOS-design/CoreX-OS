<?php

namespace App\Models\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhistleblowComplaint extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'whistleblow_complaints';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'reported_by_user_id',
        'tier',
        'subject_agency_name',
        'subject_practitioner_name',
        'subject_ffc_number',
        'subject_practitioner_email',
        'subject_practitioner_phone',
        'property_id',
        'property_address',
        'property_portal_url',
        'portal_source',
        'portal_listing_ref',
        'seller_contact_id',
        'seller_statement',
        'seller_consents_to_named_complaint',
        'agent_notes',
        'status',
        'approved_by_user_id',
        'approved_at',
        'approval_notes',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_reason',
        'sent_to_ppra_at',
        'ppra_reference_number',
        'ppra_acknowledged_at',
        'complaint_pdf_path',
    ];

    protected $casts = [
        'seller_consents_to_named_complaint' => 'boolean',
        'approved_at'          => 'datetime',
        'rejected_at'          => 'datetime',
        'sent_to_ppra_at'      => 'datetime',
        'ppra_acknowledged_at' => 'datetime',
    ];

    // ── Relationships ──

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function sellerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'seller_contact_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WhistleblowComplaintEvidence::class, 'complaint_id');
    }

    public function auditLog(): HasMany
    {
        return $this->hasMany(WhistleblowAuditLog::class, 'complaint_id');
    }
}

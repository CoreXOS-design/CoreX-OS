<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'agency_id',
        'branch_id',
        'contact_type_id', 'contact_source_id', 'created_by_user_id',
        'first_name', 'last_name', 'phone', 'email', 'notes',
        'birthday', 'id_number', 'address',
        'loaded_at', 'modified_at', 'last_contacted_at',
        'whatsapp_count', 'email_count',
        'bank_name', 'bank_account_name', 'bank_account_number',
        'bank_branch_name', 'bank_branch_code', 'bank_account_type',
    ];

    protected $casts = [
        'birthday'          => 'date',
        'loaded_at'         => 'datetime',
        'modified_at'       => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(ContactType::class, 'contact_type_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ContactSource::class, 'contact_source_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ContactTag::class, 'contact_tag')
                    ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function contactNotes(): HasMany
    {
        return $this->hasMany(ContactNote::class)->latest();
    }

    /** @deprecated Use documents() instead. Kept for backward compat during transition. */
    public function legacyDocuments(): HasMany
    {
        return $this->hasMany(ContactDocument::class)->latest();
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_contacts')
            ->withPivot('party_role')
            ->withTimestamps()
            ->latest('documents.created_at');
    }

    /**
     * Signed e-signature documents linked to this contact via pivot.
     */
    public function signedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Docuperfect\Document::class,
            'document_contact',
            'contact_id',
            'document_id'
        )->withPivot(['party_role', 'document_type', 'is_signed', 'signed_at', 'signed_pdf_path'])
         ->withTimestamps();
    }

    /**
     * Get FICA documents for this contact (legacy e-sign pivot).
     */
    public function ficaDocuments(): BelongsToMany
    {
        return $this->signedDocuments()
            ->wherePivot('document_type', 'fica')
            ->wherePivot('is_signed', true);
    }

    /**
     * FICA submissions linked to this contact (new standalone FICA form system).
     */
    public function ficaSubmissions(): HasMany
    {
        return $this->hasMany(FicaSubmission::class)->latest();
    }

    /**
     * Check FICA compliance status.
     * Checks both legacy e-sign FICA docs AND the new fica_submissions table.
     * Returns: 'complete', 'expiring', 'incomplete'
     */
    public function ficaStatus(): string
    {
        // Check new FICA submission system first
        $approvedSubmission = $this->ficaSubmissions()
            ->where('status', 'approved')
            ->orderByDesc('verified_at')
            ->first();

        if ($approvedSubmission) {
            $verifiedAt = $approvedSubmission->verified_at;
            if ($verifiedAt && $verifiedAt->diffInMonths(now()) >= 11) {
                return 'expiring';
            }
            return 'complete';
        }

        // Fall back to legacy e-sign FICA documents
        $ficaDocs = $this->ficaDocuments()->get();
        if ($ficaDocs->isEmpty()) {
            return 'incomplete';
        }
        $latest = $ficaDocs->sortByDesc('pivot.signed_at')->first();
        if ($latest && $latest->pivot->signed_at) {
            $signedAt = \Carbon\Carbon::parse($latest->pivot->signed_at);
            if ($signedAt->diffInMonths(now()) >= 11) {
                return 'expiring';
            }
            return 'complete';
        }
        return 'complete';
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ContactMatch::class)->latest();
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'contact_property')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(substr($this->first_name, 0, 1) . substr($this->last_name, 0, 1));
    }
}

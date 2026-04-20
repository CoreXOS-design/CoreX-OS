<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class FicaSubmission extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'contact_id',
        'agency_id',
        'requested_by',
        'token',
        'token_expires_at',
        'entity_type',
        'form_data',
        'status',
        'risk_rating',
        'verification_method',
        'verified_by',
        'verified_at',
        'reviewer_notes',
        'pdf_path',
        'signature_data',
        'signed_at',
        // Agent verification
        'agent_verified_by',
        'agent_verified_at',
        'agent_verification_data',
        'agent_notes',
        // Compliance officer verification
        'co_verified_by',
        'co_verified_at',
        'co_verification_data',
        'co_notes',
        'co_signature_data',
    ];

    protected $casts = [
        'form_data'                => 'array',
        'verification_method'      => 'array',
        'agent_verification_data'  => 'array',
        'co_verification_data'     => 'array',
        'token_expires_at'         => 'datetime',
        'verified_at'              => 'datetime',
        'signed_at'                => 'datetime',
        'agent_verified_at'        => 'datetime',
        'co_verified_at'           => 'datetime',
        'risk_rating'              => 'integer',
    ];

    // ── Relationships ──

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function agentVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_verified_by');
    }

    public function coVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_verified_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FicaDocument::class);
    }

    // ── Scopes ──

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'submitted']);
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    public function scopeAgentApproved(Builder $query): Builder
    {
        return $query->where('status', 'agent_approved');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    // ── Helpers ──

    public function isExpired(): bool
    {
        return $this->token_expires_at->isPast();
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isAgentApproved(): bool
    {
        return $this->status === 'agent_approved';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft'                 => 'Draft',
            'submitted'             => 'Submitted',
            'under_review'          => 'Under Review',
            'agent_approved'        => 'Agent Approved',
            'corrections_requested' => 'Corrections Requested',
            'approved'              => 'Approved',
            'rejected'              => 'Rejected',
            default                 => ucfirst($this->status),
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'draft'                 => 'gray',
            'submitted'             => 'blue',
            'under_review'          => 'yellow',
            'agent_approved'        => 'indigo',
            'corrections_requested' => 'amber',
            'approved'              => 'green',
            'rejected'              => 'red',
            default                 => 'gray',
        };
    }
}

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
        'property_id',
        'property_address',
        'seller_contact_id',
        'seller_statement',
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
        'approved_at'          => 'datetime',
        'rejected_at'          => 'datetime',
        'sent_to_ppra_at'      => 'datetime',
        'ppra_acknowledged_at' => 'datetime',
    ];

    // ── Scopes ──

    /**
     * Ruling 5 (spec esign-compliance-approval-gate.md §9.3) — agent own, branch manager branch,
     * admin all. Owners resolve to all; otherwise the Role Manager scope on
     * compliance.whistleblow.view (scope_defaults: admin=all, branch_manager=branch, agent=own),
     * fail-closed to own. The legacy binary view_all_agency grant no longer widens this — the
     * standing rule is the build spec. Applied by index(), show() and the sidebar badge alike —
     * deliberately NOT a global BranchScope (the complaint stays agency-wide by design; see
     * BranchSplitIsolationTest).
     */
    public function scopeVisibleTo($query, User $user)
    {
        // Spec §9.3 — the owner, or an explicit "View All Agency Complaints" grant, wins outright.
        // The grant is still defined, still in Role Manager, and still what an agency ticks to let
        // a branch manager read every branch's reports; it must keep meaning that.
        if ($user->isOwnerRole() || \App\Services\PermissionService::userHasExplicitPermission($user, 'compliance.whistleblow.view_all_agency')) {
            return $query;
        }

        // The appointment is the authority (Andre, 2026-09-16): the appointed Compliance Officer sees
        // every report in the agency whatever their role's data scope. Reporting Officers (and the
        // legacy no-CO fallback roles) keep their role's own / branch / all scope, as e-sign ROs do.
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId > 0 && app(\App\Services\Compliance\OfficerRegistry::class)->isCo($user, OfficerAppointment::MODULE_WHISTLEBLOW, $agencyId)) {
            return $query;
        }

        $scope = \App\Services\PermissionService::getDataScope($user, 'compliance.whistleblow') ?? 'own';

        // The "view as branch" override is the SESSION's, i.e. the browsing user's — never another
        // officer's, when a listener evaluates them inside the filer's request.
        $branchId = \App\Models\Docuperfect\EsignApproval::branchOf($user);

        return match ($scope) {
            'all'    => $query,
            'branch' => $branchId
                ? $query->where($this->getTable() . '.branch_id', $branchId)
                : $query->whereIn($this->getTable() . '.reported_by_user_id', $user->dataIdentityIds()),
            default  => $query->whereIn($this->getTable() . '.reported_by_user_id', $user->dataIdentityIds()),
        };
    }

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

    public function subjects(): HasMany
    {
        return $this->hasMany(WhistleblowComplaintSubject::class, 'complaint_id')->orderBy('display_order');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WhistleblowComplaintEvidence::class, 'complaint_id');
    }

    public function auditLog(): HasMany
    {
        return $this->hasMany(WhistleblowAuditLog::class, 'complaint_id');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(WhistleblowEmailLog::class, 'complaint_id');
    }

    // ── Accessors ──

    public function getSubjectsSummaryAttribute(): string
    {
        $subjects = $this->subjects;
        if ($subjects->isEmpty()) {
            return '—';
        }
        $first = $subjects->first()->agency_name;
        $remaining = $subjects->count() - 1;
        return $remaining > 0 ? "{$first} + {$remaining} more" : $first;
    }
}

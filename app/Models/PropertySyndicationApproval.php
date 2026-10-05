<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\PermissionService;
use App\Services\Syndication\SyndicationApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One request for syndication approval on a Property — layer 3 of the
 * market gate. Spec: .ai/specs/syndication-approval-gate.md §4.2.
 *
 * The AUTHORITATIVE approval fact lives on the property itself
 * (`properties.syndication_approved_at`, spec §4.1/D2). This table is the
 * audit trail: who asked, when, who decided, why, and whether the approver
 * was actually emailed. Rows are never mutated into a new meaning — a cancel
 * or a revoke writes its own `withdrawn` row.
 */
class PropertySyndicationApproval extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $table = 'property_syndication_approvals';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'property_id',
        'status',
        'requested_by_user_id',
        'requested_at',
        'request_note',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'notified_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at'   => 'datetime',
        'notified_at'  => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Own / branch / agency visibility — mirrors FicaSubmission::scopeVisibleTo()
     * exactly (the closest existing analogue: an approval queue with the same
     * three-tier shape). Spec §7.3.
     *
     * 'all'    → every request in the agency (AgencyScope already bounds the tenant)
     * 'branch' → their branch only; NO branch resolvable ⇒ nothing (fail closed)
     * 'own'    → only the requests they raised themselves
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = static::approvalScopeFor($user);

        if ($scope === 'all') {
            return $query;
        }

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();

            return $branchId
                ? $query->where($this->getTable() . '.branch_id', $branchId)
                : $query->whereRaw('1 = 0');
        }

        return $query->where($this->getTable() . '.requested_by_user_id', $user->id);
    }

    /**
     * A chosen approver (and an owner/agency-admin) is agency-wide by
     * definition — they are the person the agency named to clear its stock.
     * Everyone else falls back to their `properties` data scope, defaulting
     * to 'own'. Kept here (not in the service) so list visibility and the
     * service's write authority read the same rule.
     */
    public static function approvalScopeFor(User $user): string
    {
        if (SyndicationApprovalService::isFallbackApprover($user)) {
            return 'all';
        }

        $scope = PermissionService::getDataScope($user, 'properties');

        if (SyndicationApprovalService::isChosenApprover($user)) {
            // A chosen approver with no explicit properties data-scope is
            // agency-wide by definition — they are the person the agency named
            // to clear its stock. An explicit 'branch' scope still narrows them.
            return $scope ?? 'all';
        }

        return $scope ?? 'own';
    }
}

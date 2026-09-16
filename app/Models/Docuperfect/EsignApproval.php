<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Compliance approval ledger for an e-sign document — one row per hold.
 *
 * Spec: .ai/specs/esign-compliance-approval-gate.md §5.5 / §6.
 */
class EsignApproval extends Model
{
    use SoftDeletes, BelongsToAgency;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    /** The sender cancelled the document while it was held or declined — nothing left to decide. */
    public const STATUS_WITHDRAWN = 'withdrawn';
    /** A later ledger row replaced this one (the sender asked again, or the CO overrode). */
    public const STATUS_SUPERSEDED = 'superseded';

    /** Rows an officer can still act on. Everything else is history. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_DECLINED];

    protected $table = 'esign_approvals';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'signature_template_id',
        'document_id',
        'requested_by_user_id',
        'status',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'is_override',
    ];

    protected $casts = [
        'decided_at'  => 'datetime',
        'is_override' => 'boolean',
    ];

    public function signatureTemplate(): BelongsTo
    {
        return $this->belongsTo(SignatureTemplate::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDeclined(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DECLINED);
    }

    /**
     * Ruling 5 — agent own, branch manager branch, admin all. Copies CommandTask::scopeVisibleTo():
     * null (no grant) is fail-closed to 'own'.
     */
    public function scopeVisibleTo(Builder $query, User $user, ?string $scope): Builder
    {
        $branchId = self::branchOf($user);

        return match ($scope) {
            'all'    => $query,
            'branch' => $branchId
                ? $query->where('branch_id', $branchId)
                : $query->whereIn('requested_by_user_id', $user->dataIdentityIds()),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->whereIn('requested_by_user_id', $user->dataIdentityIds()),
        };
    }

    /**
     * The branch a scope check is measured against. The "view as branch" override lives in the
     * SESSION, so it belongs only to the person browsing; when a listener evaluates some other
     * officer inside the sender's request, that officer's own branch is the truth.
     */
    public static function branchOf(User $user): ?int
    {
        $viewer = auth()->user();
        if ($viewer && (int) $viewer->id === (int) $user->id) {
            return $user->effectiveBranchId();
        }

        return $user->branch_id ? (int) $user->branch_id : null;
    }
}

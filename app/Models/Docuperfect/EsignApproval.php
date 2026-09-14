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
        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->where('branch_id', $user->effectiveBranchId())
                : $query->whereIn('requested_by_user_id', $user->dataIdentityIds()),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->whereIn('requested_by_user_id', $user->dataIdentityIds()),
        };
    }
}

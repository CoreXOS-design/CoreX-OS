<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A billable seat that was released, and the 30-day hold that followed.
 *
 * AT-278. Spec: .ai/specs/agent-seat-release-lock.md §4.2
 *
 * One row per release CYCLE. `reinstated_at IS NULL` means the row is OPEN —
 * and an open row IS the lock. Closing the row (rather than deleting it) is
 * what makes an agency's headcount churn readable months later, which is the
 * whole point of the record.
 *
 * NOT soft-deletable and NOT agency-scoped — see the migration's header for
 * both decisions.
 */
class AgentSeatRelease extends Model
{
    public const REASON_DELETED     = 'deleted';
    public const REASON_DEACTIVATED = 'deactivated';

    public const VIA_ELAPSED        = 'elapsed';
    public const VIA_OWNER_OVERRIDE = 'owner_override';

    protected $table = 'agent_seat_releases';

    protected $fillable = [
        'agency_id',
        'user_id',
        'released_at',
        'released_by_user_id',
        'release_reason',
        'reinstatable_at',
        'reinstated_at',
        'reinstated_by_user_id',
        'reinstated_via',
        'override_reason',
    ];

    protected $casts = [
        'released_at'     => 'datetime',
        'reinstatable_at' => 'datetime',
        'reinstated_at'   => 'datetime',
    ];

    // ── Relations ────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        // withTrashed: the whole point is that this user is usually archived.
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id')->withTrashed();
    }

    public function reinstatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reinstated_by_user_id')->withTrashed();
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /** Rows that have not been closed — i.e. the person has not come back. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('reinstated_at');
    }

    public function scopeForAgency(Builder $query, ?int $agencyId): Builder
    {
        return $query->where('agency_id', $agencyId);
    }

    // ── State ────────────────────────────────────────────────────────────────

    /** Has the hold run its course? An elapsed row is still open, just no longer blocking. */
    public function isElapsed(): bool
    {
        return now()->greaterThanOrEqualTo($this->reinstatable_at);
    }

    /** Open AND still inside the window — the only state that refuses a reinstatement. */
    public function isBlocking(): bool
    {
        return $this->reinstated_at === null && ! $this->isElapsed();
    }

    /**
     * Whole days still to run, rounded UP — 0.2 days left is still "1 day",
     * because telling an admin "0 days" while refusing them is a lie.
     */
    public function remainingDays(): int
    {
        if ($this->isElapsed()) {
            return 0;
        }

        return (int) ceil(now()->floatDiffInDays($this->reinstatable_at));
    }

    public function unlockDate(): CarbonInterface
    {
        return $this->reinstatable_at;
    }
}

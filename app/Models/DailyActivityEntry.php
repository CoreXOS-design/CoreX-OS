<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;

/**
 * Module 6 (M6.1) — Eloquent model for the daily_activity_entries table.
 *
 * Pre-Module 6 the table was written via raw DB::updateOrInsert() from
 * DailyActivityController::store(). The model is introduced now so M6.3's
 * ProvisionalPointService and M6.4's PointStateService can route every
 * state transition through Eloquent + observers.
 *
 * point_state state machine:
 *
 *   provisional  ── feedback captured ──►  confirmed
 *        │                                     │
 *        └─── stale + no feedback ─►  revoked  ┘
 *                                              │
 *                                              ▼
 *                                          overridden  (BM/admin manual)
 *
 * Only the points services should mutate point_state. Controllers write
 * via the service so the transition + audit are atomic.
 */
final class DailyActivityEntry extends Model
{
    // Branch follows the owning agent (user_id), stamped context-independently so
    // auto-credit writes from services/jobs don't NULL it. The class's own branch()
    // relation below overrides the trait's — behaviour is unchanged.
    use BelongsToBranch, InheritsBranchFromParent;

    protected function branchParent(): array
    {
        return [\App\Models\User::class, 'user_id'];
    }

    public const STATE_PROVISIONAL = 'provisional';
    public const STATE_CONFIRMED   = 'confirmed';
    public const STATE_REVOKED     = 'revoked';
    public const STATE_OVERRIDDEN  = 'overridden';

    public const SOURCE_MANUAL        = 'manual';
    public const SOURCE_AUTO_CALENDAR = 'auto_calendar';
    public const SOURCE_AUTO_INSTANT  = 'auto_instant';   // SPINE-1
    public const SOURCE_AUTO_OTHER    = 'auto_other';

    /**
     * M6.5 — the locked set of point_state values that contribute to an
     * agent's achievement total / target progress / any BM-snapshot
     * judgement. Confirmed + overridden are real points; provisional
     * is pending evidence + revoked is reversed work — neither counts.
     *
     * The MUST-USE-EVERYWHERE rule (Johan's standing integrity guard
     * for the points engine): every controller, service, calculator,
     * scorecard or dashboard query that turns rows into a total MUST
     * apply this set as a whereIn('point_state', ...) clause AND
     * apply ACHIEVEMENT_TOTAL_SOURCES below. Missing either filter on
     * any site is a gaming hole.
     *
     * Raw DB::table() callers cannot reach scopeIncludedInAchievementTotal()
     * (it's an Eloquent scope), so these constants exist as the inline
     * whereIn() values to keep every callsite aligned. PR reviewers
     * grep for both constants to verify M6.5 compliance.
     */
    public const ACHIEVEMENT_TOTAL_STATES = [
        self::STATE_CONFIRMED,
        self::STATE_OVERRIDDEN,
    ];

    /**
     * M6.5 — the locked set of source values that contribute to an
     * agent's achievement total. Manual + the two auto pipelines
     * (calendar M6.3, instant SPINE-1+). 'auto_other' is reserved for
     * future imports that haven't been validated as score-eligible
     * yet — it is DELIBERATELY excluded from the total.
     */
    public const ACHIEVEMENT_TOTAL_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_AUTO_CALENDAR,
        self::SOURCE_AUTO_INSTANT,
    ];

    protected $table = 'daily_activity_entries';

    protected $fillable = [
        'activity_date',
        'period',
        'user_id',
        'agency_id',
        'branch_id',
        'activity_definition_id',
        'value',
        'point_state',
        'source',
        'calendar_event_id',
        'subject_type',     // SPINE-1
        'subject_id',       // SPINE-1
        'confirmed_at',
        'revoked_at',
        'revoke_reason',
        'overridden_by_user_id',
        'override_reason',
        'override_audit_json',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'activity_date'       => 'date',
        'value'               => 'integer',
        'confirmed_at'        => 'datetime',
        'revoked_at'          => 'datetime',
        'override_audit_json' => 'array',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activityDefinition(): BelongsTo
    {
        return $this->belongsTo(ActivityDefinition::class);
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CommandCenter\CalendarEvent::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ── Scopes ──

    public function scopeProvisional(Builder $q): Builder
    {
        return $q->where('point_state', self::STATE_PROVISIONAL);
    }

    public function scopeConfirmed(Builder $q): Builder
    {
        return $q->where('point_state', self::STATE_CONFIRMED);
    }

    public function scopeRevoked(Builder $q): Builder
    {
        return $q->where('point_state', self::STATE_REVOKED);
    }

    public function scopeOverridden(Builder $q): Builder
    {
        return $q->where('point_state', self::STATE_OVERRIDDEN);
    }

    public function scopeAutoCredited(Builder $q): Builder
    {
        return $q->whereIn('source', [
            self::SOURCE_AUTO_CALENDAR,
            self::SOURCE_AUTO_INSTANT,
            self::SOURCE_AUTO_OTHER,
        ]);
    }

    public function scopeManual(Builder $q): Builder
    {
        return $q->where('source', self::SOURCE_MANUAL);
    }

    /**
     * Rows that count toward an agent's running total. Confirmed and
     * overridden are real points; provisional + revoked are NOT.
     * Used by daily/period summary aggregations.
     *
     * M6.5 PRE-EXISTING SHAPE: state filter only — kept as-is so the
     * existing ActivityDefinitionScopeTest assertion continues to hold
     * exactly as written.
     */
    public function scopeCountedTowardTotal(Builder $q): Builder
    {
        return $q->whereIn('point_state', [self::STATE_CONFIRMED, self::STATE_OVERRIDDEN]);
    }

    /**
     * M6.5 — the FULL achievement-total filter (state + source). Every
     * total-computation site that uses Eloquent should chain this scope;
     * raw DB::table() sites should apply the two ACHIEVEMENT_TOTAL_*
     * constants inline. Same locked set in both forms.
     *
     * Provisional points are visible on dashboards / daily screens but
     * NOT included here — they need feedback to convert. Revoked
     * points stay in the table for audit but never count. auto_other
     * is reserved + excluded.
     */
    public function scopeIncludedInAchievementTotal(Builder $q): Builder
    {
        return $q
            ->whereIn('point_state', self::ACHIEVEMENT_TOTAL_STATES)
            ->whereIn('source', self::ACHIEVEMENT_TOTAL_SOURCES);
    }

    // ── Convenience predicates ──

    public function isProvisional(): bool { return $this->point_state === self::STATE_PROVISIONAL; }
    public function isConfirmed(): bool   { return $this->point_state === self::STATE_CONFIRMED; }
    public function isRevoked(): bool     { return $this->point_state === self::STATE_REVOKED; }
    public function isOverridden(): bool  { return $this->point_state === self::STATE_OVERRIDDEN; }
}

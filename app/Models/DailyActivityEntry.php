<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    public const STATE_PROVISIONAL = 'provisional';
    public const STATE_CONFIRMED   = 'confirmed';
    public const STATE_REVOKED     = 'revoked';
    public const STATE_OVERRIDDEN  = 'overridden';

    public const SOURCE_MANUAL        = 'manual';
    public const SOURCE_AUTO_CALENDAR = 'auto_calendar';
    public const SOURCE_AUTO_INSTANT  = 'auto_instant';   // SPINE-1
    public const SOURCE_AUTO_OTHER    = 'auto_other';

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
     */
    public function scopeCountedTowardTotal(Builder $q): Builder
    {
        return $q->whereIn('point_state', [self::STATE_CONFIRMED, self::STATE_OVERRIDDEN]);
    }

    // ── Convenience predicates ──

    public function isProvisional(): bool { return $this->point_state === self::STATE_PROVISIONAL; }
    public function isConfirmed(): bool   { return $this->point_state === self::STATE_CONFIRMED; }
    public function isRevoked(): bool     { return $this->point_state === self::STATE_REVOKED; }
    public function isOverridden(): bool  { return $this->point_state === self::STATE_OVERRIDDEN; }
}

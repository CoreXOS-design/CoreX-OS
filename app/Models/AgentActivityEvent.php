<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only agent activity log.
 *
 * Foundation for Phase 5 auto-activity tracking; lands now so domain-event
 * listeners can start writing, but nothing reads it for points calculation
 * yet.
 *
 *   - NO updated_at (immutable history) — $timestamps disabled, only
 *     created_at is set explicitly.
 *   - NO BelongsToAgency trait — events span pillars and listeners need to
 *     write without an Auth context (queues, console commands). agency_id
 *     IS on the row; filter manually when needed.
 *   - NO SoftDeletes — append-only.
 *
 * Spec: .ai/specs/mic-complete-spec.md §3.2.7 / §14.6.
 */
final class AgentActivityEvent extends Model
{
    protected $table = 'agent_activity_events';

    /**
     * Append-only: created_at managed manually; no updated_at on the schema.
     */
    public $timestamps = false;

    protected $dates = ['created_at'];

    protected $fillable = [
        'agency_id', 'user_id',
        'event_type',
        'subject_type', 'subject_id',
        'payload',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

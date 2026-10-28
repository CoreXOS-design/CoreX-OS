<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 1 - a thing to do inside a step (road-to-sold.md section 5.1 / 5.4).
 *
 *   core   (Type A) - completed by a domain event: event_key names the event class, event_filter narrows it.
 *   custom (Type B) - defined by the agency, recurring, captured by a form (the capture form is phase 2).
 *
 * Archive = soft delete; is_active = switched off but kept. Written only through RecipeService.
 */
class RtsTask extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const TYPE_CORE   = 'core';
    public const TYPE_CUSTOM = 'custom';

    public const UNITS = ['hour' => 'Hour', 'day' => 'Day', 'week' => 'Week', 'month' => 'Month'];

    public const CAPTURE_KINDS = ['none' => 'Notes only', 'property' => 'Properties', 'contact' => 'Contacts', 'both' => 'Properties and contacts'];

    public const EVIDENCE = ['none' => 'Not required', 'note' => 'A note', 'note_or_photo' => 'A note or a photo'];

    protected $table = 'rts_tasks';

    protected $fillable = [
        'agency_id', 'track', 'step_id', 'key', 'label', 'type', 'event_key', 'event_filter', 'recurrence_unit',
        'capture_kind', 'evidence_required', 'is_system', 'is_active', 'sort_order', 'legacy_activity_definition_id', 'mapping_default', 'needs_step',
    ];

    protected $casts = [
        'event_filter' => 'array',
        'mapping_default' => 'array',
        'needs_step'   => 'boolean',
        'is_system'    => 'boolean',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(RtsStep::class, 'step_id')->withTrashed();
    }

    public function levelTasks(): HasMany
    {
        return $this->hasMany(RtsLevelTask::class, 'task_id');
    }

    public function isCore(): bool
    {
        return $this->type === self::TYPE_CORE;
    }
}

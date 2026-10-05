<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A block/milestone on one agency's timeline — a SNAPSHOT of the defaults taken
 * at start, then freely editable. Spec §7.3.
 */
class AgencyTimelineItem extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'agency_timeline_items';

    protected $fillable = [
        'timeline_id', 'kind', 'title', 'body', 'sort_order', 'due_date', 'offset_days',
        'is_public', 'is_go_live', 'auto_complete_trigger', 'status',
        'completed_at', 'completed_by', 'completed_source', 'is_custom', 'source_default_id',
    ];

    protected $casts = [
        'sort_order'   => 'integer',
        'offset_days'  => 'integer',
        'due_date'     => 'date',
        'is_public'    => 'boolean',
        'is_go_live'   => 'boolean',
        'is_custom'    => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function timeline(): BelongsTo
    {
        return $this->belongsTo(AgencyTimeline::class, 'timeline_id');
    }

    public function isBlock(): bool
    {
        return $this->kind === AgencyTimelineDefaultItem::KIND_BLOCK;
    }

    public function isMilestone(): bool
    {
        return $this->kind === AgencyTimelineDefaultItem::KIND_MILESTONE;
    }
}

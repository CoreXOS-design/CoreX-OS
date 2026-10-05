<?php

namespace App\Models\Platform;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One onboarding timeline per agency (platform-owned, not tenant-scoped).
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §7
 */
class AgencyTimeline extends Model
{
    use SoftDeletes;

    public const STATUS_RUNNING = 'running';
    public const STATUS_LIVE = 'live';
    public const STATUS_PAUSED = 'paused';

    protected $table = 'agency_timelines';

    protected $fillable = [
        'agency_id', 'token', 'start_date', 'status',
        'public_link_enabled', 'started_by', 'live_at', 'agreement_template_id',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'public_link_enabled' => 'boolean',
        'live_at'             => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AgencyTimelineItem::class, 'timeline_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgencyTimelineEvent::class, 'timeline_id');
    }

    public function publicUrl(): string
    {
        return url('/agency-timeline/' . $this->token);
    }
}

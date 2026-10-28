<?php

namespace App\Models\FeatureRollout;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One agency's gradual feature rollout plan. Spec: agency-feature-rollout.md §4.
 */
class AgencyFeatureRollout extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id', 'source_plan_id', 'status', 'interval_days', 'weekdays_only', 'skip_holidays', 'holiday_country',
        'release_time', 'side_by_side', 'started_on', 'paused_at', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'interval_days' => 'integer',
        'weekdays_only' => 'boolean',
        'skip_holidays' => 'boolean',
        'side_by_side'  => 'boolean',
        'started_on'    => 'date',
        'paused_at'     => 'datetime',
        'completed_at'  => 'datetime',
    ];

    public function waves(): HasMany
    {
        return $this->hasMany(AgencyRolloutWave::class, 'rollout_id')->orderBy('lane')->orderBy('position');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AgencyRolloutItem::class, 'rollout_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}

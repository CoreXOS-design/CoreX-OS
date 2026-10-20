<?php

namespace App\Models\FeatureRollout;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A wave in ONE agency's rollout plan — its own copy of a default wave.
 * Lane 1 is the main order; lane 2 is the rentals lane when sales and rentals run side by side.
 */
class AgencyRolloutWave extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'rollout_id', 'source_wave_id', 'lane', 'track', 'position', 'title', 'intro_text',
        'scheduled_for', 'access_reviewed_at', 'access_reviewed_by', 'admin_notified_at', 'released_at', 'customised_at',
    ];

    protected $casts = [
        'lane'               => 'integer',
        'position'           => 'integer',
        'scheduled_for'      => 'date',
        'access_reviewed_at' => 'datetime',
        'released_at'        => 'datetime',
        'customised_at'      => 'datetime',
        'admin_notified_at'  => 'datetime',
    ];

    public function rollout(): BelongsTo
    {
        return $this->belongsTo(AgencyFeatureRollout::class, 'rollout_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AgencyRolloutItem::class, 'wave_id');
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }
}

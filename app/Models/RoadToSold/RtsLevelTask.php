<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 1 - a task inside a level, with that level's target per period and the points one completion earns.
 * Taking a task out of a level archives this row; putting it back restores it (BUILD_STANDARD 5a).
 */
class RtsLevelTask extends Model
{
    use BelongsToAgency, SoftDeletes;

    /** The largest target / points a screen accepts. */
    public const MAX_TARGET = 100000;
    public const MAX_POINTS = 1000000;

    protected $table = 'rts_level_tasks';

    protected $fillable = ['agency_id', 'level_id', 'task_id', 'target', 'points', 'required_for_signoff'];

    protected $casts = ['target' => 'integer', 'points' => 'decimal:2', 'required_for_signoff' => 'boolean'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(RtsLevel::class, 'level_id')->withTrashed();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(RtsTask::class, 'task_id')->withTrashed();
    }
}

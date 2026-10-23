<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 1 - an agency-named stage an agent works through (road-to-sold.md section 8.1). A new agency gets Level 1 seeded
 * with a small set of tasks and two empty shells for the agency to fill. Which agent is on which level, and the sign-off, are phase 4.
 */
class RtsLevel extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'rts_levels';

    protected $fillable = ['agency_id', 'track', 'number', 'name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'number' => 'integer'];

    public function levelTasks(): HasMany
    {
        return $this->hasMany(RtsLevelTask::class, 'level_id');
    }
}

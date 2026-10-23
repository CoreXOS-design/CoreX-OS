<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 phase 1 - one agent's own target and/or points for a task, over the level default (road-to-sold.md section 5.2).
 * level_id null = every level (level_key 0 in the unique key). The screen that sets these is a later phase; the engine already honours them.
 */
class RtsUserTaskOverride extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'rts_user_task_overrides';

    protected $fillable = ['agency_id', 'user_id', 'task_id', 'level_id', 'level_key', 'target', 'points', 'set_by_user_id'];

    protected $casts = ['target' => 'integer', 'points' => 'decimal:2'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(RtsTask::class, 'task_id')->withTrashed();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}

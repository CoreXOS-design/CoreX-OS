<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per change to a task's step / event, or per restore of its default. Append-only: never edited or deleted. */
class RtsTaskMappingAuditEntry extends Model
{
    use BelongsToAgency;

    protected $table = 'rts_task_mapping_audit';

    public $timestamps = false;

    protected $fillable = ['agency_id', 'track', 'task_id', 'task_label', 'action', 'changed_by_user_id', 'old_values', 'new_values', 'note', 'changed_at'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'changed_at' => 'datetime'];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}

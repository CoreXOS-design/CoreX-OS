<?php

namespace App\Models\CommandCenter;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommandTaskNote extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'command_task_id',
        'user_id',
        'body',
        'agency_id',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CommandTask::class, 'command_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

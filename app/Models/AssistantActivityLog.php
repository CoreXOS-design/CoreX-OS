<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-267 — one row per meaningful thing an assistant did (opened / edited /
 * created / deleted a property, contact or deal). Written by
 * App\Http\Middleware\LogAssistantActivity. Append-only; read on the agent's
 * My Assistants → Activity tab.
 */
class AssistantActivityLog extends Model
{
    use BelongsToAgency;

    protected $table = 'assistant_activity_log';

    /** Append-only log — created_at only, no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'assistant_assignment_id',
        'assistant_user_id',
        'agent_user_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'route_name',
        'url',
        'method',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AssistantAssignment::class, 'assistant_assignment_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}

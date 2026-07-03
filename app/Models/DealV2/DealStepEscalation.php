<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-158 DR2 WS6 — one fired notification/escalation rung (idempotency + audit).
 * @see database/migrations/2026_07_03_400000_create_deal_step_escalations_table.php
 */
class DealStepEscalation extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'deal_id', 'deal_step_instance_id',
        'level_key', 'kind', 'recipient_user_id', 'channels', 'context', 'notified_at',
    ];

    protected $casts = [
        'channels'    => 'array',
        'context'     => 'array',
        'notified_at' => 'datetime',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(DealStepInstance::class, 'deal_step_instance_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(DealV2::class, 'deal_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}

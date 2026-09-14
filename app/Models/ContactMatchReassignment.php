<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-Core-Matches, Johan's ruling 1 + Task 3 — one immutable row per
 * buyer reassignment. Mirrors RentalApplicationStatusHistory's append-only
 * pattern: written only through ::record(), never updated. `reason` is
 * NOT NULL — Johan's model is that the manager has a conversation with
 * the agent before moving the buyer, so the why is required, not optional.
 */
class ContactMatchReassignment extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'contact_match_id',
        'from_agent_id',
        'to_agent_id',
        'moved_by_user_id',
        'reason',
    ];

    public static function record(
        ContactMatch $match,
        ?int $fromAgentId,
        int $toAgentId,
        int $movedByUserId,
        string $reason,
    ): self {
        return static::create([
            'agency_id'         => $match->agency_id,
            'contact_match_id'  => $match->id,
            'from_agent_id'     => $fromAgentId,
            'to_agent_id'       => $toAgentId,
            'moved_by_user_id'  => $movedByUserId,
            'reason'            => $reason,
        ]);
    }

    public function contactMatch(): BelongsTo
    {
        return $this->belongsTo(ContactMatch::class);
    }

    public function fromAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_agent_id');
    }

    public function toAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_agent_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by_user_id');
    }
}

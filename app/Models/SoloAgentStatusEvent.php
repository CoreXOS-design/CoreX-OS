<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a single-agent account's timeline: who changed what, when, why. Append-only. */
class SoloAgentStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['signup_id', 'from_status', 'to_status', 'event', 'actor_user_id', 'reason'];

    public function signup(): BelongsTo
    {
        return $this->belongsTo(SoloAgentSignup::class, 'signup_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withoutGlobalScopes()->withTrashed();
    }
}

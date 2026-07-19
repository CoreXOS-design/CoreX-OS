<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConversation extends Model
{
    use SoftDeletes;

    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'ellie');

        if ($scope === 'all') return $query;

        // AI conversations are private — only the owner can see them.
        //
        // AT-267 — DELIBERATELY NOT dataIdentityIds(). This is the one model on the
        // PRIVATE_TO_SELF allowlist (AssistantVisibilityCoverageTest): an assistant must NOT
        // read their Assigned Agent's Ellie conversations. Not everything an agent can see is
        // something the agent meant to delegate — an agent talks to Ellie the way they'd think
        // out loud, about their own deals, their own targets, sometimes their own colleagues.
        // Handing that to an assistant is a privacy breach, not a feature.
        return $query->where('user_id', $user->id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** .ai/specs/attorney-portal.md §4 - one signed-in browser. Only the hash of its cookie token is stored. */
class AttorneyPortalSession extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'attorney_portal_sessions';

    protected $fillable = ['access_id', 'token_hash', 'expires_at', 'revoked_at', 'ip', 'user_agent'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function access(): BelongsTo
    {
        return $this->belongsTo(AttorneyPortalAccess::class, 'access_id')->withoutGlobalScopes();
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}

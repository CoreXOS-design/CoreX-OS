<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One browser session inside the demo, attributed to a grant.
 *
 * Spec: .ai/specs/demo-access-control.md §4.4
 *
 * Lives on PRIMARY. `session_token` is the UUID in the demo host's signed
 * corex_demo_session cookie.
 */
class DemoSession extends Model
{
    protected $fillable = [
        'demo_access_grant_id',
        'session_token',
        'started_at',
        'last_seen_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(DemoAccessGrant::class, 'demo_access_grant_id');
    }

    public function pageViews(): HasMany
    {
        return $this->hasMany(DemoPageView::class);
    }

    /**
     * Throttled heartbeat. The gate re-checks every request; writing
     * last_seen_at on each would hammer the row for no extra signal.
     */
    public function touchSeen(): void
    {
        if ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subMinute())) {
            $this->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }
}

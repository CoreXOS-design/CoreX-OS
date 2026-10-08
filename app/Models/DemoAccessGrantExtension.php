<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One applied "Add time" extension of a demo access grant. Append-only.
 *
 * Spec: .ai/specs/demo-access-control.md §4.6, §9.1
 *
 * Written by DemoAccessService::extend() inside the SAME transaction that moves the
 * grant's clock, so the history can never lag the change. Lives on PRIMARY and is
 * not tenant-scoped (system-owner sales data), like the grant it belongs to.
 */
class DemoAccessGrantExtension extends Model
{
    /** Immutable record — created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'demo_access_grant_id',
        'actor_user_id',
        'event_id',
        'hours_added',
        'basis',
        'previous_expires_at',
        'new_expires_at',
        'previous_expiry_hours',
        'new_expiry_hours',
        'note',
        'created_at',
    ];

    protected $casts = [
        'hours_added'           => 'integer',
        'previous_expires_at'   => 'datetime',
        'new_expires_at'        => 'datetime',
        'previous_expiry_hours' => 'integer',
        'new_expiry_hours'      => 'integer',
        'created_at'            => 'datetime',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(DemoAccessGrant::class, 'demo_access_grant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

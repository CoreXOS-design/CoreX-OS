<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence that a grant accepted a specific, immutable T&C version.
 *
 * Spec: .ai/specs/demo-access-control.md §4.3
 *
 * Created via firstOrCreate against the (grant, version) UNIQUE index, so a
 * double-click / two tabs / a retried request produces ONE row.
 */
class DemoTncAcceptance extends Model
{
    protected $fillable = [
        'demo_access_grant_id',
        'demo_tnc_version_id',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(DemoAccessGrant::class, 'demo_access_grant_id');
    }

    /**
     * The version as it was accepted. Because DemoTncVersion is immutable, this
     * renders the ORIGINAL body forever — even after v2, v3 are published.
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DemoTncVersion::class, 'demo_tnc_version_id');
    }
}

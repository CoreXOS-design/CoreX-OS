<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/leases.md §3.2 — joint tenants, N-party, never assumed 1-2.
 * No agency scope of its own; always reached through its parent Lease,
 * which is agency-scoped.
 */
class LeaseTenant extends Model
{
    protected $fillable = [
        'lease_id',
        'contact_id',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4) — see Lease::property(). */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    /** Same reasoning — a soft-deleted tenant contact must not vanish from the tenancy list. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }
}

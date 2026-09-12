<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reopen/resubmit, 2026-09-08 — `generation` joins (rental_application_id,
 * kind) in the unique key: every submission round gets its own row per
 * kind, never overwritten by a later resubmit (see the migration's own
 * docblock — this was "the signature landmine" the reopen build was
 * required to fix as part of the same prompt, not after).
 *
 * QA1 multi-tenancy sweep, 2026-09-12 — this table had no `agency_id`
 * column at all until today's migration, and no `BelongsToAgency` —
 * unlike every sibling model on this same rental application. Not
 * reachable via any route (the only write site always scopes by an
 * already-token-resolved application, and no route binds a signature id
 * directly), but a tenant table with no agency_id violates CLAUDE.md
 * Non-negotiable #7 outright and was queryable fully unscoped by default.
 */
class RentalApplicationSignature extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'rental_application_id', 'agency_id', 'kind', 'generation', 'signature_path', 'signed_at', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'generation' => 'integer',
        'signed_at' => 'datetime',
    ];

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }
}

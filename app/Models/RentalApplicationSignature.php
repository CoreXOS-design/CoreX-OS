<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reopen/resubmit, 2026-09-08 — `generation` joins (rental_application_id,
 * kind) in the unique key: every submission round gets its own row per
 * kind, never overwritten by a later resubmit (see the migration's own
 * docblock — this was "the signature landmine" the reopen build was
 * required to fix as part of the same prompt, not after).
 */
class RentalApplicationSignature extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'rental_application_id', 'kind', 'generation', 'signature_path', 'signed_at', 'ip_address', 'user_agent',
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

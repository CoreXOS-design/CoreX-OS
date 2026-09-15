<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-392 authoriser flow — Johan: "properly logged in audit trail - same
 * fight we had with contacts and properties history - properly tracked so
 * evidence will show who authorised who declined. what was done at what
 * step etc." Mirrors ContactAuditLog / PropertyAuditLog column-for-column
 * (see the migration's own docblock for the one deliberate difference —
 * no DB-trigger backstop, not needed here). SoftDeletes per Non-Negotiable
 * #1 — append-only in practice, never user-deletable.
 */
class RentalApplicationAuditLog extends Model
{
    use BelongsToAgency, SoftDeletes;

    public $timestamps = false;

    protected $table = 'rental_application_audit_log';

    protected $fillable = [
        'rental_application_id', 'agency_id', 'branch_id', 'user_id',
        'actor_type', 'actor_label', 'source',
        'event_category', 'event_type', 'is_override',
        'old_values', 'new_values', 'metadata', 'reason',
        'human_summary', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'is_override' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForApplication($q, int $rentalApplicationId)
    {
        return $q->where('rental_application_id', $rentalApplicationId);
    }
}

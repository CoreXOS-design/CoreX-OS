<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §45.7 item 4 — append-only record that a reminder milestone was handled for a COMPUTED
 * In/Out due item. UNIQUE (lease_id, type, due_on, milestone): a lease whose due date moves (a new move-out date) is a new
 * due item and is reminded afresh; the same one is never reminded twice.
 */
class RentalInspectionDueNotice extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id', 'lease_id', 'type', 'due_on', 'milestone', 'recipient_user_id', 'channel', 'status', 'detail', 'created_at',
    ];

    protected $casts = [
        'due_on' => 'date',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}

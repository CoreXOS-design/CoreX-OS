<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §52 — append-only record that a signing-window reminder milestone (`lead` = the window is
 * about to close with someone still outstanding, `passed` = it closed) was handled for an inspection. UNIQUE (inspection,
 * milestone): a re-run, or a catch-up after a missed tick, never reminds twice.
 */
class RentalInspectionSigningNotice extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const MILESTONE_LEAD = 'lead';
    public const MILESTONE_PASSED = 'passed';

    protected $fillable = ['agency_id', 'rental_inspection_id', 'milestone', 'recipient_user_id', 'channel', 'status', 'detail', 'created_at'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id')->withTrashed();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PROVISIONAL — cc5, 2026-09-15. cc2 owns the real model/CRUD for decline
 * reason templates; this file exists only so cc5's own half (agent send
 * step + email merge) has a class to read from while cc2's build lands in
 * a separate worktree against the same shared corex_qa1 DB. Columns match
 * cc2's real, already-migrated table (confirmed live, 2026-09-15):
 * id, agency_id, reason, guidance, sort_order, created_by, timestamps,
 * deleted_at. To be deleted/reconciled with cc2's own model file once
 * their commit merges — see .ai/specs/rental-applications.md AT-410b.
 */
class RentalApplicationDeclineReasonTemplate extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'reason', 'guidance', 'sort_order', 'created_by'];

    protected $casts = ['sort_order' => 'integer'];

    /**
     * cc2's own read method (confirmed signature, 2026-09-15) — kept here,
     * matching theirs, only so cc5's code has something to call until their
     * real model file merges and replaces this one entirely.
     */
    public static function activeFor(int $agencyId)
    {
        return static::where('agency_id', $agencyId)->orderBy('sort_order')->orderBy('id')->get();
    }
}

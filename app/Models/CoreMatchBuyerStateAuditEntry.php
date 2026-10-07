<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per save that changed which Buyer Pipeline statuses take a buyer off Core
 * Matches (.ai/specs/core-matches.md — "Won / Lost buyers"). Append-only: never edited or deleted.
 */
class CoreMatchBuyerStateAuditEntry extends Model
{
    use BelongsToAgency;

    protected $table = 'core_match_buyer_state_audit';

    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'changed_by_user_id', 'old_values', 'new_values', 'changed_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_at' => 'datetime',
    ];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}

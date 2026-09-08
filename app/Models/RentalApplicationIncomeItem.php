<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-392 Round 9 (item 5) — one line of the agent's income capture, agent
 * can add as many as needed. SoftDeletes (non-negotiable #1) — matches
 * PayrollPayslipLine's precedent for a financial line-item ledger.
 */
class RentalApplicationIncomeItem extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id', 'rental_application_assessment_id', 'description', 'amount', 'sort_order',
        'struck_out_at', 'struck_out_by_user_id', 'added_by_user_id', 'replaces_item_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'sort_order' => 'integer',
        'struck_out_at' => 'datetime',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RentalApplicationAssessment::class, 'rental_application_assessment_id');
    }

    public function struckOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'struck_out_by_user_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    /**
     * Johan: "auth can rather strike out and re-add a value than edit a
     * value. this way we have the evidence needed of who did what." The row
     * this one replaced (if it was added via the strike-and-replace flow),
     * and the row that replaced THIS one (if it has since been struck and
     * replaced in turn) — both directions, so either row can render "this
     * figure was replaced by that one" regardless of which one you're
     * looking at.
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_item_id');
    }

    public function replacedBy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'replaces_item_id');
    }

    /** Johan: struck out ≠ deleted — stays visible, drops out of the total. */
    public function isStruckOut(): bool
    {
        return $this->struck_out_at !== null;
    }
}

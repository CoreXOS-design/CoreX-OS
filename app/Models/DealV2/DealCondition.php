<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-334 — a per-deal active suspensive condition. A deal is a SET of these;
 * each is active → met/failed/waived. Waive carries reason + addendum for audit.
 */
class DealCondition extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'deal_conditions';

    protected $fillable = [
        'deal_id', 'agency_id', 'key', 'status', 'options', 'waived_reason', 'addendum_ref',
    ];

    protected $casts = [
        'options' => 'array',
    ];

    public const STATUSES = ['active', 'met', 'failed', 'waived'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /** Satisfied for the finance-secured gate = met OR waived. */
    public function isSatisfied(): bool
    {
        return in_array($this->status, ['met', 'waived'], true);
    }
}

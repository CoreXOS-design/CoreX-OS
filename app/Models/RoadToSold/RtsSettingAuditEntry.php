<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-454 — one row per Road to Sold settings save that changed something.
 * Append-only: never edited or deleted (.ai/specs/road-to-sold.md §3.1).
 */
class RtsSettingAuditEntry extends Model
{
    use BelongsToAgency;

    protected $table = 'rts_settings_audit';

    public $timestamps = false;

    protected $fillable = ['agency_id', 'track', 'changed_by_user_id', 'old_values', 'new_values', 'changed_at'];

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

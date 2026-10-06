<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per save that changed a Viewing Pack cover setting
 * (.ai/specs/viewing-pack.md §14 — audit). Append-only: never edited or deleted.
 */
class ViewingPackCoverAuditEntry extends Model
{
    use BelongsToAgency;

    protected $table = 'viewing_pack_cover_audit';

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

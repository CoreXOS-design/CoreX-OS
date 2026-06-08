<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row written every time an admin restores a record from the
 * Soft Deletes Register. Spec: .ai/specs/soft-deletes-admin.md §3.
 *
 * Deliberately NOT agency-scoped: it is an internal system audit log, not
 * surfaced in the register UI. `agency_id` is captured as a plain column so the
 * full restore history is preserved for owners; agency scoping of any future
 * reporting view is applied at that view, not here.
 */
class SoftDeleteRestoration extends Model
{
    protected $fillable = [
        'model_type',
        'model_id',
        'model_label',
        'agency_id',
        'restored_by_user_id',
        'restored_at',
    ];

    protected $casts = [
        'restored_at' => 'datetime',
    ];

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_user_id');
    }
}

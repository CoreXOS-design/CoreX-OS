<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-366 (Q4 enabler) — one row per agent branch move (dated), so the report can
 * attribute activity to the branch the agent was in at the time.
 *
 * Deliberately NOT BelongsToAgency-scoped: it is written by an observer for the
 * SUBJECT user and must carry that user's agency_id, not the acting user's. It is
 * an append-only system log; agency_id is stamped explicitly by the observer.
 */
class UserBranchHistory extends Model
{
    protected $table = 'user_branch_history';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'agency_id', 'from_branch_id', 'to_branch_id', 'moved_at', 'moved_by_user_id',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function movedBy(): BelongsTo { return $this->belongsTo(User::class, 'moved_by_user_id'); }
}

<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\User;
use App\Services\RoadToSold\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-454 — an agent's hold on a tracked property. Spec: .ai/specs/road-to-sold.md §10. */
class RtsHold extends Model
{
    use BelongsToAgency, BelongsToBranch, SoftDeletes;

    protected $table = 'rts_holds';

    protected $fillable = [
        'agency_id', 'branch_id', 'track', 'tracked_property_id', 'user_id', 'last_outcome_id',
        'started_at', 'expires_at', 'released_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'expires_at'  => 'datetime',
        'released_at' => 'datetime',
    ];

    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        return Scope::apply($query, $user, $requestedScope);
    }

    /** Still in force at $at: not released and not yet past expires_at. */
    public function scopeActive($query, $at = null)
    {
        return $query->whereNull('released_at')->where('expires_at', '>', $at ?? now());
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}

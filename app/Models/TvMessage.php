<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;

class TvMessage extends Model
{
    // BelongsToAgency (AT-424): TV messages are agency data. Without it every
    // agency's admins listed/edited each other's messages by id.
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'branch_id',
        'created_by_user_id',
        'title',
        'message',
        'display_area',
        'is_enabled',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActiveForBranch(Builder $q, int $branchId): Builder
    {
        $now = now();

        // TV screens are code-based and unauthenticated, so AgencyScope does
        // not apply. Clamp "all branches" messages to the screen's own agency
        // (AT-424: one agency's global messages played on every agency's TVs).
        $agencyId = (int) DB::table('branches')->where('id', $branchId)->value('agency_id');

        return $q->where('is_enabled', true)
            ->where('agency_id', $agencyId)
            ->where(function ($x) use ($branchId) {
                $x->whereNull('branch_id')
                  ->orWhere('branch_id', $branchId);
            })
            ->where(function ($x) use ($now) {
                $x->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($x) use ($now) {
                $x->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            // branch messages first, then global
            ->orderByRaw('case when branch_id is null then 1 else 0 end')
            ->orderBy('id', 'desc');
    }
}

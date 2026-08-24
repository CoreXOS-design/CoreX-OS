<?php

namespace App\Models;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Viewing Pack — the buyer-facing mirror of the Presentation (spec §1).
 * Header record; selected properties hang off viewing_pack_properties and the
 * documents under each property off viewing_pack_documents.
 *
 * Tenant-owned (BelongsToAgency + AgencyScope) and soft-deleted. "Archive" is a
 * soft delete; restore() recovers it. Soft-deleting a pack cascades to its
 * children (and restore brings them back) so a pack is never half-visible and
 * never orphans rows — see booted().
 */
class ViewingPack extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'agency_id',
        'branch_id',
        'contact_id',
        'agent_id',
        'calendar_event_id',
        'tour_at',
        'status',
        'title',
    ];

    protected $casts = [
        'tour_at' => 'datetime',
    ];

    /** Status values — draft while building, ready once finalised for the tour. */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_READY];

    protected static function booted(): void
    {
        // Soft-delete cascade: archiving a pack archives its children so they
        // are scoped out with it; restoring the pack restores the children it
        // took down. We never force-delete packs, but guard for completeness.
        static::deleting(function (ViewingPack $pack) {
            if ($pack->isForceDeleting()) {
                return;
            }
            foreach ($pack->viewingPackProperties()->get() as $child) {
                $child->delete();
            }
        });

        static::restoring(function (ViewingPack $pack) {
            foreach ($pack->viewingPackProperties()->onlyTrashed()->get() as $child) {
                $child->restore();
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function viewingPackProperties(): HasMany
    {
        return $this->hasMany(ViewingPackProperty::class);
    }

    /**
     * AT-112 — role-level visibility WITHIN an agency, mirroring
     * Presentation::scopeVisibleTo(). AgencyScope already isolates by agency and
     * BranchScope handles Split Branches; this narrows to the caller's data scope:
     *   all    → no extra filter (admin / owner)
     *   branch → the caller's branch (branch manager / office admin)
     *   own    → packs the caller owns as agent (agent)
     *   null   → no rows (AT-265 fail-closed on an unseeded grants table)
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = PermissionService::getDataScope($user, 'viewing_packs');

        return match ($scope) {
            'all'    => $query,
            'branch' => $query->where($this->qualifyColumn('branch_id'), $user->effectiveBranchId()),
            // AT-267 §7.2 — 'own' for an assistant means their AGENT's; dataIdentityIds() is
            // [self] for a normal user (identical behaviour) and [agent, self] for an assistant.
            'own'    => $query->whereIn($this->qualifyColumn('agent_id'), $user->dataIdentityIds()),
            default  => $query->whereRaw('1 = 0'),
        };
    }

    /** True when this user may see this specific pack under their data scope. */
    public function isVisibleTo(User $user): bool
    {
        $scope = PermissionService::getDataScope($user, 'viewing_packs');

        return match ($scope) {
            'all'    => true,
            'branch' => (int) $this->branch_id === (int) $user->effectiveBranchId(),
            'own'    => in_array((int) $this->agent_id, $user->dataIdentityIds(), true),
            default  => false,
        };
    }
}

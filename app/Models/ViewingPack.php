<?php

namespace App\Models;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Concerns\BelongsToAgency;
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
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
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
}

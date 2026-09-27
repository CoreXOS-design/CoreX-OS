<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inventory.md — the counted contents of a furnished or
 * partly-furnished property, grouped by room. A genuinely separate
 * document from RentalInspection: not condition grading, quantity plus
 * description, produced once at move-in and compared at move-out. Never a
 * tab on the inspection (Johan, explicit) — its own record, its own list
 * screen, attached to the property and the lease.
 */
class RentalInventory extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_AWAITING_SIGNATURE = 'awaiting_signature';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id',
        'property_id',
        'lease_id',
        'status',
        'signing_deadline_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'archived_by_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'signing_deadline_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $inventory) {
            if (empty($inventory->status)) {
                $inventory->status = self::STATUS_DRAFT;
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RentalInventoryLine::class)->where('is_retired', false)->orderBy('sort_order')->orderBy('id');
    }

    /** Every line ever added, including retired — the append-only view for history. */
    public function allLines(): HasMany
    {
        return $this->hasMany(RentalInventoryLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(RentalInventorySignature::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RentalInventoryPhoto::class);
    }

    /** §12 — every room an agent has explicitly confirmed has nothing in it. */
    public function roomMarks(): HasMany
    {
        return $this->hasMany(RentalInventoryRoomMark::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    /**
     * "Produced at move-in" (Johan) — one per lease, not one per property
     * (a new tenancy gets a fresh inventory; the old one stays as history
     * for the tenancy it actually describes, same reasoning as
     * RentalInspection::currentFor()'s own per-lease scoping).
     */
    public static function currentFor(Lease $lease): ?self
    {
        return self::where('lease_id', $lease->id)
            ->whereNotIn('status', [self::STATUS_CANCELLED])
            ->latest('id')
            ->first();
    }

    public static function start(Property $property, Lease $lease, User $by): self
    {
        if (self::currentFor($lease)) {
            throw new \LogicException('This lease already has an inventory. Cancel it before starting another.');
        }

        return self::create([
            'agency_id' => $property->agency_id,
            'property_id' => $property->id,
            'lease_id' => $lease->id,
            'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §0b, Johan 2026-09-22 — "selecting inventory from the property we
     * already know which property its for. done simple." One click from the
     * property, straight into the capture surface: resume the current
     * (non-cancelled) inventory for the property's active lease if one
     * exists, or start a fresh one transparently — no separate "create"
     * step. Returns null only when the property genuinely has no active
     * lease to attach to (§0a's still-open sale-property question).
     */
    public static function resolveOrStartFor(Property $property, User $by): ?self
    {
        $lease = Lease::where('property_id', $property->id)->where('status', Lease::STATUS_ACTIVE)->first();
        if (! $lease) {
            return null;
        }

        return self::currentFor($lease) ?? self::start($property, $lease, $by);
    }

    /**
     * §13, Johan's approved mockup — "a furnished flat is re-let with the
     * same contents, and re-typing forty lines is the work we are supposed
     * to be doing for them." The most recent OTHER (non-cancelled) inventory
     * for the SAME property, excluding this one — a prior tenancy's own
     * inventory, since RentalInventory::start() refuses a second inventory
     * per lease. Ordered by id desc (not completed_at) deliberately: a
     * property mid-way through a still-open prior tenancy has no completed
     * date yet, and "the last thing recorded here" is still the useful
     * starting point to copy from even if that record itself never reached
     * completed status.
     */
    public function priorInventory(): ?self
    {
        return self::where('property_id', $this->property_id)
            ->where('id', '!=', $this->id)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->latest('id')
            ->first();
    }

    /**
     * §13 — copies every active line from $source into $this, quantity +
     * description + condition_key (the condition is copied as a STARTING
     * POINT for the agent to confirm or correct, never asserted as this
     * tenancy's own verified condition — Johan's own framing is "re-typing
     * is the work we're saving them," not "skip re-checking the goods").
     * Deliberately does NOT copy photos: a photo is evidence of what this
     * move-in actually looked like, and copying an old photo forward would
     * misrepresent today's condition as something it was never verified
     * against. Additive only — never touches $this's own existing lines,
     * so running it twice, or after already adding some items by hand, only
     * ever adds more, never overwrites or duplicates-detects (the agent
     * removes an unwanted copied line the same way they remove any other).
     *
     * @return \Illuminate\Support\Collection<int, RentalInventoryLine>
     */
    public function copyLinesFrom(self $source, User $by): \Illuminate\Support\Collection
    {
        return $source->lines->map(function (RentalInventoryLine $line) use ($by) {
            return RentalInventoryLine::create([
                'agency_id' => $this->agency_id,
                'rental_inventory_id' => $this->id,
                'property_room_id' => $line->property_room_id,
                'room_label' => $line->room_label,
                'quantity' => $line->quantity,
                'description' => $line->description,
                'condition_key' => $line->condition_key,
                'created_by_user_id' => $by->id,
            ]);
        });
    }

    /** Every tenant on the lease, plus the landlord if resolvable, who does NOT yet have a live disposition. */
    public function outstandingSignatories(): \Illuminate\Support\Collection
    {
        $existing = $this->signatures()
            ->whereIn('party_role', [RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::PARTY_LANDLORD])
            ->get(['party_role', 'party_contact_id']);

        $outstanding = collect();

        $tenantContactIds = LeaseTenant::where('lease_id', $this->lease_id)->pluck('contact_id');
        foreach ($tenantContactIds as $contactId) {
            $already = $existing->contains(fn ($s) => $s->party_role === RentalInventorySignature::PARTY_TENANT
                && (int) $s->party_contact_id === (int) $contactId);
            if (! $already) {
                $outstanding->push(['party_role' => RentalInventorySignature::PARTY_TENANT, 'party_contact_id' => $contactId]);
            }
        }

        $landlordContactId = $this->property?->sellerOwnerContact()?->id;
        if ($landlordContactId) {
            $already = $existing->contains(fn ($s) => $s->party_role === RentalInventorySignature::PARTY_LANDLORD);
            if (! $already) {
                $outstanding->push(['party_role' => RentalInventorySignature::PARTY_LANDLORD, 'party_contact_id' => $landlordContactId]);
            }
        }

        return $outstanding;
    }

    public function hasAgentSignature(): bool
    {
        return $this->signatures()
            ->where('party_role', RentalInventorySignature::PARTY_AGENT)
            ->where('disposition', RentalInventorySignature::DISPOSITION_SIGNED)
            ->exists();
    }

    /**
     * §12 — every non-retired PropertyRoom belonging to this inventory's
     * property that has neither an active line nor an explicit "nothing in
     * this room" mark (RentalInventoryRoomMark). A room nobody opened is not
     * the same as a room an agent actually checked and found empty, and
     * markCompleted() below refuses until this returns empty.
     */
    public function unvisitedRooms(): \Illuminate\Support\Collection
    {
        $rooms = PropertyRoom::where('property_id', $this->property_id)
            ->where('is_retired', false)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $roomIdsWithLines = $this->lines()->whereNotNull('property_room_id')->pluck('property_room_id')->unique();
        $roomIdsMarkedEmpty = $this->roomMarks()->pluck('property_room_id')->unique();

        return $rooms->reject(fn (PropertyRoom $room) => $roomIdsWithLines->contains($room->id)
            || $roomIdsMarkedEmpty->contains($room->id))->values();
    }

    /**
     * §12, Johan — decided, not a question: an inventory is what a tenant
     * gets charged against at move-out, and one that says "complete" with
     * nothing captured is worse than no inventory at all, because it looks
     * authoritative. Two gates enforced here, server-side, so no other path
     * (API, bulk action, anything that ever calls this method) can bypass
     * them by skipping a disabled button:
     *   1. At least one line must exist anywhere on the inventory.
     *   2. Every room the property has must have been visited — either it
     *      has a line, or it carries an explicit "nothing in this room" mark
     *      (unvisitedRooms() above). A room nobody opened is not an empty
     *      room.
     * Checked before the existing signature gate below so the more
     * fundamental problem (nothing was ever recorded) surfaces first.
     */
    public function markCompleted(): void
    {
        if ($this->lines()->count() === 0) {
            throw new \LogicException('Cannot complete: nothing has been recorded yet. Add at least one item, or mark each room as having nothing in it, before completing this inventory.');
        }

        $unvisited = $this->unvisitedRooms();
        if ($unvisited->isNotEmpty()) {
            $names = $unvisited->pluck('label')->implode(', ');
            $verb = $unvisited->count() === 1 ? 'has' : 'have';
            throw new \LogicException("Cannot complete: {$names} {$verb} not been checked yet. Add items to it, or mark it as having nothing in it, before completing this inventory.");
        }

        $outstanding = $this->outstandingSignatories();
        if ($outstanding->isNotEmpty()) {
            $first = $outstanding->first();
            if ($first['party_role'] === RentalInventorySignature::PARTY_TENANT) {
                $name = Contact::find($first['party_contact_id'])?->full_name ?? 'A tenant';
                throw new \LogicException("Cannot complete: {$name} has neither signed nor been marked as refusing.");
            }
            throw new \LogicException('Cannot complete: the landlord has neither signed nor been marked as refusing.');
        }
        if (! $this->hasAgentSignature()) {
            throw new \LogicException('Cannot complete an inventory without the agent\'s own signature.');
        }

        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
    }

    public function cancel(User $by, string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
            'cancel_reason' => $reason,
        ])->save();
    }

    /** Same OWN/BRANCH/AGENCY scoping convention as RentalInspection::scopeVisibleTo(). */
    public function scopeVisibleTo(Builder $query, User $user, ?string $requestedScope = null): Builder
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_inventories');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            return $query->whereIn('rental_inventories.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }
}

<?php

namespace App\Models;

use App\Contracts\SignedDocumentDistributable;
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
 * screen. A PROPERTY feature, sale or rental (§0a/§15) — always attached to
 * the property, and ADDITIONALLY to the lease when one exists (a rental
 * mid-tenancy). A sale property (or a rental between tenancies) attaches to
 * the property alone; `lease_id` is nullable for exactly this reason.
 *
 * §41-follow-up (Job 3, 2026-09-28) — implements SignedDocumentDistributable
 * so a completed inventory gets the SAME file/email/share-link treatment
 * RentalInspection already has, via the shared
 * App\Services\Distribution\SignedDocumentDistributionService — see that
 * class's own docblock and .ai/specs/signed-document-distribution.md. This
 * model owns only its own module-specific decisions (who the recipients
 * are, what the public link resolves to); the service knows nothing about
 * inventories, leases, or properties.
 */
class RentalInventory extends Model implements SignedDocumentDistributable
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
        'public_token',
        'public_token_expires_at',
    ];

    protected $casts = [
        'signing_deadline_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'public_token_expires_at' => 'datetime',
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

    /**
     * A completed inventory is a signed record; a cancelled one is closed.
     * Neither may be edited — draft is the ONLY status new content (lines,
     * photos, marks, dispositions, signatures) may be written against.
     * `awaiting_signature` is a declared-but-never-actually-set status on
     * this model (RentalInventory never transitions into it — only
     * RentalInspection does), kept only so a future signing-in-progress
     * stage doesn't silently fall through this check as editable.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** @throws \App\Exceptions\RentalInventoryNotEditableException */
    public function assertEditable(): void
    {
        if (! $this->isDraft()) {
            throw new \App\Exceptions\RentalInventoryNotEditableException($this);
        }
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
     * §0a/§15 — the PROPERTY-LEVEL counterpart to currentFor(Lease): a sale
     * property (or a rental property between tenancies) has no active Lease
     * to key an inventory against, so this scopes by property_id alone,
     * restricted to inventories that were themselves started without a
     * lease (lease_id NULL) — a lease-attached inventory for this same
     * property is a different record, found via currentFor(), never this.
     */
    public static function currentForProperty(Property $property): ?self
    {
        return self::where('property_id', $property->id)
            ->whereNull('lease_id')
            ->whereNotIn('status', [self::STATUS_CANCELLED])
            ->latest('id')
            ->first();
    }

    /**
     * §0a/§15 — starts a property-level inventory with no Lease to attach
     * to. Same one-per-subject discipline as start(Property, Lease, User),
     * scoped to property_id instead of lease_id.
     */
    public static function startForProperty(Property $property, User $by): self
    {
        if (self::currentForProperty($property)) {
            throw new \LogicException('This property already has an inventory. Cancel it before starting another.');
        }

        return self::create([
            'agency_id' => $property->agency_id,
            'property_id' => $property->id,
            'lease_id' => null,
            'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §0b, Johan 2026-09-22 — "selecting inventory from the property we
     * already know which property its for. done simple." One click from the
     * property, straight into the capture surface: resume the current
     * (non-cancelled) inventory for the property's active lease if one
     * exists, or start a fresh one transparently — no separate "create"
     * step.
     *
     * §0a/§15, Johan 2026-09-28 — "inventory was specifically specced not
     * only for rentals. sales will also need it... it should be on
     * properties." A property with no active lease (every sale property,
     * and a rental property between tenancies) now falls through to the
     * property-level inventory instead of returning null — an inventory
     * attaches to the property alone when there is no lease to also attach
     * to. This method therefore never returns null for a real Property.
     */
    public static function resolveOrStartFor(Property $property, User $by): self
    {
        $lease = Lease::where('property_id', $property->id)->where('status', Lease::STATUS_ACTIVE)->first();
        if ($lease) {
            return self::currentFor($lease) ?? self::start($property, $lease, $by);
        }

        return self::currentForProperty($property) ?? self::startForProperty($property, $by);
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
        // Conductor brief 2026-09-29 — a superseded wet-ink row (a corrected
        // wrong upload, or the awaiting_wet_ink row a real upload just
        // resolved) must not count as "this party is accounted for"; its
        // replacement is the live disposition. Same filter
        // RentalInspection::outstandingSignatories() already applies.
        $existing = $this->signatures()
            ->whereIn('party_role', [RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::PARTY_LANDLORD])
            ->whereNull('superseded_at')
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
     * Conductor brief 2026-09-29 — the first party (if any) still sitting in
     * "paper sent, not yet returned." Distinct from outstandingSignatories()
     * (which only reports a party with NO live row at all): a party marked
     * awaiting_wet_ink DOES have a live row, so they never appear there, but
     * they have not actually provided evidence yet either — markCompleted()
     * below blocks on this the same way it blocks on a truly outstanding
     * party.
     */
    public function firstAwaitingWetInkSignatory(): ?RentalInventorySignature
    {
        return $this->signatures()
            ->where('disposition', RentalInventorySignature::DISPOSITION_AWAITING_WET_INK)
            ->whereNull('superseded_at')
            ->first();
    }

    /**
     * Every signing party — tenant(s), the owner (landlord/seller), the
     * agent — one row each, with the party's current live signature (if
     * any). Mirrors RentalInspection::signatureSummaryRows() exactly (same
     * shape, same "not_required" convention for an unresolvable landlord),
     * built here for the report PDF's "print for signature" mode and its
     * own signature section, which previously iterated raw
     * $inventory->signatures instead of the full required roster.
     *
     * @return array<int, array{role:string, name:?string, signature:?RentalInventorySignature, not_required:bool}>
     */
    public function signatureSummaryRows(): array
    {
        $liveSignatureFor = fn (string $partyRole, ?int $contactId = null) => $this->signatures->first(
            fn (RentalInventorySignature $s) => $s->party_role === $partyRole
                && $s->superseded_at === null
                && ($contactId === null || (int) $s->party_contact_id === (int) $contactId)
        );

        $rows = [];

        foreach ($this->lease?->tenants ?? [] as $leaseTenant) {
            $rows[] = [
                'role' => 'Tenant',
                'name' => $leaseTenant->contact?->full_name,
                'signature' => $liveSignatureFor(RentalInventorySignature::PARTY_TENANT, $leaseTenant->contact_id),
                'not_required' => false,
            ];
        }

        $owner = $this->property?->sellerOwnerContact();
        $ownerRole = $this->lease_id ? 'Landlord' : 'Seller';
        $rows[] = [
            'role' => $ownerRole,
            'name' => $owner?->full_name,
            'signature' => $owner ? $liveSignatureFor(RentalInventorySignature::PARTY_LANDLORD, $owner->id) : null,
            'not_required' => ! $owner,
        ];

        $rows[] = [
            'role' => 'Agent',
            'name' => $this->createdBy?->name,
            'signature' => $liveSignatureFor(RentalInventorySignature::PARTY_AGENT),
            'not_required' => false,
        ];

        return $rows;
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
            // Johan, 2026-09-28 — "the red completion warning must list the
            // unchecked rooms by name, each clickable to jump to that
            // space." Structured exception (message text unchanged) so the
            // controller can hand the frontend real {id, label} rows to
            // link, not a sentence to parse room names back out of.
            throw new \App\Exceptions\RentalInventoryUnvisitedRoomsException(
                $unvisited->map(fn (PropertyRoom $room) => ['id' => $room->id, 'label' => $room->label])->all()
            );
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

        // Conductor brief 2026-09-29 — a party marked "sent for a paper
        // signature" is not truly outstanding (they have a live row) but
        // has not actually provided evidence yet either. Checked after the
        // outstanding-signatories gate (a genuinely blank party is the more
        // fundamental problem) and before the agent-signature gate (the
        // agent cannot sign until every party is fully resolved anyway, so
        // this can never be reached with the agent already signed).
        if ($awaiting = $this->firstAwaitingWetInkSignatory()) {
            $name = $awaiting->party_role === RentalInventorySignature::PARTY_TENANT
                ? (Contact::find($awaiting->party_contact_id)?->full_name ?? 'A tenant')
                : 'The landlord';
            throw new \LogicException("Cannot complete: {$name} is still awaiting a paper signature — upload the signed scan (or mark them as refused) before completing.");
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

    /**
     * §41-follow-up (Job 3) — same mechanism as RentalInspection::
     * generatePublicLink() (see that method's own docblock for the full
     * reasoning). Regenerating overwrites whatever token already existed,
     * immediately invalidating any previously-issued link — that IS the
     * revoke mechanism; there is no separate revoked flag to also check.
     * Expiry is a fixed constant, not agency-configurable — see
     * RentalInventorySetting::DEFAULT_PUBLIC_LINK_EXPIRY_DAYS's own
     * docblock for why, unlike Inspections' own version of this method.
     */
    public function generatePublicLink(?int $expiryDays = null): string
    {
        $days = $expiryDays ?? RentalInventorySetting::DEFAULT_PUBLIC_LINK_EXPIRY_DAYS;

        $this->forceFill([
            'public_token' => \Illuminate\Support\Str::random(48),
            'public_token_expires_at' => now()->addDays($days),
        ])->save();

        return $this->public_token;
    }

    /** Revoke: clear the token — any existing link (PDF already printed, forwarded email) stops working immediately. */
    public function revokePublicLink(): void
    {
        $this->forceFill(['public_token' => null, 'public_token_expires_at' => null])->save();
    }

    public function publicLinkIsValid(): bool
    {
        return $this->public_token !== null
            && $this->public_token_expires_at !== null
            && $this->public_token_expires_at->isFuture();
    }

    /** Same withoutGlobalScopes()/token-scoped/expiry-checked pattern as RentalInspection::findByPublicToken(). */
    public static function findByPublicToken(string $token): ?self
    {
        return self::withoutGlobalScopes()
            ->where('public_token', $token)
            ->where('public_token_expires_at', '>', now())
            ->first();
    }

    // ── SignedDocumentDistributable (§41-follow-up, Job 3, 2026-09-28) ──

    public function distributionProperty(): ?Property
    {
        return $this->property;
    }

    /**
     * Every signing party: the lease's own tenant(s) (when this is a
     * lease-attached inventory — empty for a property-level one, §15) plus
     * the property's owner (Property::sellerOwnerContact(), the SAME
     * resolver outstandingSignatories() above already uses). The owner's
     * role reads 'landlord' for a lease-attached inventory and 'seller' for
     * a property-level one — same distinction §15's `$ownerPartyLabel`
     * display fix already drew on the show page, carried here rather than
     * re-decided. A party with no email on file is silently excluded.
     *
     * @return array<int, array{contact_id: int|null, name: string, email: string, role: string}>
     */
    public function distributionRecipients(): array
    {
        $recipients = [];

        foreach ($this->lease?->tenants ?? [] as $leaseTenant) {
            $contact = $leaseTenant->contact;
            if ($contact?->email) {
                $recipients[] = [
                    'contact_id' => $contact->id,
                    'name' => $contact->full_name,
                    'email' => $contact->email,
                    'role' => 'tenant',
                ];
            }
        }

        $owner = $this->property?->sellerOwnerContact();
        if ($owner?->email) {
            $recipients[] = [
                'contact_id' => $owner->id,
                'name' => $owner->full_name,
                'email' => $owner->email,
                'role' => $this->lease_id ? 'landlord' : 'seller',
            ];
        }

        return $recipients;
    }

    /** The agent who ran this inventory — whose mailbox an AUTOMATIC send uses by default. A manual Resend may override this (see the controller). */
    public function distributionAgent(): ?User
    {
        return $this->createdBy;
    }

    public function distributionSubject(): string
    {
        return 'Inventory report — ' . ($this->property?->buildDisplayAddress() ?? '');
    }

    public function distributionDocumentLabel(): string
    {
        return 'Inventory report';
    }

    public function distributionSourceType(): string
    {
        return 'rental_inventory_report';
    }

    public function distributionSourceId(): int
    {
        return $this->id;
    }

    public function hasValidPublicLink(): bool
    {
        return $this->publicLinkIsValid();
    }

    public function publicShareUrl(): ?string
    {
        return $this->public_token ? route('rental-inventories.public.show', $this->public_token) : null;
    }
}

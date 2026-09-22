<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §3.2, amended (leases.md §9) — the event a
 * set of observations happens inside. Anchored to a `Lease` (required,
 * authoritative "which tenancy") with `property_id` as a denormalized
 * convenience column only (§2 pillar connections).
 */
class RentalInspection extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_AD_HOC = 'ad_hoc';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING_SIGNATURE = 'awaiting_signature';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id',
        'lease_id',
        'property_id',
        'type',
        'status',
        'scheduled_for',
        'fault_report_deadline_at',
        'signing_deadline_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancel_reason',
        'archived_by_user_id',
        'created_by_user_id',
        // §17 — the header block. Snapshotted onto the inspection at start,
        // editable (updateDetails()) until the inspection completes.
        'electricity_meter_reading',
        'water_meter_reading',
        'furnished_status',
        'property_type',
        'keys_count',
        'keys_description',
        'remotes_count',
        'remotes_description',
        'move_in_date_recorded',
        // Johan, 2026-09-21, from Retha's real paper out-inspection form:
        // a single free-text summary for the whole inspection, at the
        // foot — hers reads "OVERALL - APARTMENT CLEAN - FAIR - PARTIALLY
        // FURNISHED". Plain mutable column, like cancel_reason — this is
        // the inspection's own summary, not an append-only evidentiary
        // fact like an Observation.
        'overall_notes',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'fault_report_deadline_at' => 'datetime',
        'signing_deadline_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'keys_count' => 'integer',
        'remotes_count' => 'integer',
        'move_in_date_recorded' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $inspection) {
            // property_id is denormalized from the lease — always, never independently
            // set (§2/§3.2 docblock in the migration).
            if (empty($inspection->property_id) && $inspection->lease_id) {
                $inspection->property_id = Lease::withoutGlobalScopes()->find($inspection->lease_id)?->property_id;
            }
            if (empty($inspection->status)) {
                $inspection->status = self::STATUS_DRAFT;
            }
        });
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(RentalInspectionObservation::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(RentalInspectionDiscrepancy::class);
    }

    /**
     * §17, Johan 2026-09-21 — one free-text note per room, per walkthrough
     * (Retha's paper form: a notes box under every room table). Every note
     * ever recorded, oldest first, same as observations() — "current" for
     * a given room is the latest one, resolved by the caller (same pattern
     * as an item's currentObservation()), never a mutable column here.
     */
    public function roomNotes(): HasMany
    {
        return $this->hasMany(RentalInspectionRoomNote::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(RentalInspectionSignature::class);
    }

    /**
     * §20.13, 2026-09-22 — every photo on this inspection, tagged or not:
     * untagged (the tray), room-tagged (a general shot, no single item), or
     * item-tagged (via rental_inspection_observation_id). The single source
     * of truth for the tray/room views; item-level display still reads
     * RentalInspectionObservation::photos() (unaffected — that relation
     * only ever matched item-tagged rows and still does).
     */
    public function photos(): HasMany
    {
        return $this->hasMany(RentalInspectionPhoto::class);
    }

    public function scopeOfType(Builder $q, ?string $type): Builder
    {
        return $type ? $q->where('type', $type) : $q;
    }

    public function scopeWithUnresolvedDiscrepancy(Builder $q): Builder
    {
        return $q->whereHas('discrepancies', fn (Builder $d) => $d->whereNull('resolved_at'));
    }

    /** §11 — cannot complete while any linked discrepancy is unresolved. */
    public function hasUnresolvedDiscrepancy(): bool
    {
        return $this->discrepancies()->whereNull('resolved_at')->exists();
    }

    /**
     * §15.4/§15.7/§16 — every tenant on this inspection's own lease, plus the
     * landlord if Property::sellerOwnerContact() resolves one, who does NOT
     * yet have a live disposition (signed, refused, or wet_ink — a
     * superseded wet_ink row does not count, §16.3) row on THIS inspection.
     * Empty means every required party is accounted for — the one thing
     * both RentalInspectionSignature::capture()'s agent-signs-last rule
     * (§15.2a) and the eventual completion guard (§15.7) both ask.
     *
     * @return \Illuminate\Support\Collection<int, array{party_role: string, party_contact_id: int}>
     */
    public function outstandingSignatories(): \Illuminate\Support\Collection
    {
        // §16 — a superseded wet-ink row (a corrected wrong upload) must not
        // count as "this party is accounted for"; its replacement is the
        // live disposition.
        $existing = $this->signatures()
            ->whereIn('party_role', [RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::PARTY_LANDLORD])
            ->whereNull('superseded_at')
            ->get(['party_role', 'party_contact_id']);

        $outstanding = collect();

        $tenantContactIds = \App\Models\LeaseTenant::where('lease_id', $this->lease_id)->pluck('contact_id');
        foreach ($tenantContactIds as $contactId) {
            $already = $existing->contains(fn ($s) => $s->party_role === RentalInspectionSignature::PARTY_TENANT
                && (int) $s->party_contact_id === (int) $contactId);
            if (! $already) {
                $outstanding->push(['party_role' => RentalInspectionSignature::PARTY_TENANT, 'party_contact_id' => $contactId]);
            }
        }

        $landlordContactId = $this->property?->sellerOwnerContact()?->id;
        if ($landlordContactId) {
            $already = $existing->contains(fn ($s) => $s->party_role === RentalInspectionSignature::PARTY_LANDLORD);
            if (! $already) {
                $outstanding->push(['party_role' => RentalInspectionSignature::PARTY_LANDLORD, 'party_contact_id' => $landlordContactId]);
            }
        }

        return $outstanding;
    }

    /** §15.1 — the agent always signs; this is the one check for whether they already have. */
    public function hasAgentSignature(): bool
    {
        return $this->signatures()
            ->where('party_role', RentalInspectionSignature::PARTY_AGENT)
            ->where('disposition', RentalInspectionSignature::DISPOSITION_SIGNED)
            ->exists();
    }

    /**
     * §5/§7 — OWN/BRANCH/AGENCY scoping, layered on top of the hard
     * AgencyScope boundary. Same PermissionService::getDataScope() +
     * clampScope() convention as Lease::scopeVisibleTo() and rental
     * applications, so the existing role-manager scope UI covers this
     * module without a new mechanism.
     */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_inspections');
        $scope = \App\Services\PermissionService::clampScope($requestedScope, $maxScope);

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn (Builder $p) => $p->where('properties.branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            return $query->whereIn('rental_inspections.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * §3.5/§0.7, widened by §15.3 (2026-09-20) — an in- or out-inspection
     * moves to awaiting_signature once the walkthrough is done, opening the
     * signing step. Previously out-inspection only; Johan's fuller ruling
     * ("inspections both in and out needs all party signatures") requires
     * the same step on both. TYPE_AD_HOC stays excluded — §15 names "both
     * in and out" specifically, and an ad-hoc mid-tenancy check keeps its
     * existing lighter-weight lifecycle. Guarded the same way completion is:
     * cannot proceed while a discrepancy is still unresolved (§11).
     */
    public function startAwaitingSignature(): void
    {
        if (! in_array($this->type, [self::TYPE_IN, self::TYPE_OUT], true)) {
            throw new \LogicException('Only an in- or out-inspection has a signing window.');
        }
        if ($this->hasUnresolvedDiscrepancy()) {
            throw new \LogicException('Cannot start the signing window while a discrepancy is unresolved.');
        }

        $this->forceFill([
            'status' => self::STATUS_AWAITING_SIGNATURE,
            'signing_deadline_at' => now()->addDays(RentalInspectionSetting::signingWindowDaysFor($this->agency_id)),
        ])->save();
    }

    /**
     * §3.5/§11/§15.7 — completing an in-inspection opens the tenant's
     * fault-report window from that moment. Johan's fuller 2026-09-20
     * ruling: "its form part of the lease agreement so without signatures
     * its not an accepted document" — an in- or out-inspection now
     * requires EVERY tenant and the landlord (if resolvable) to have a
     * disposition (signed or refused), AND the agent's own signature,
     * before it can complete. TYPE_AD_HOC is exempt — §15 names "both in
     * and out" specifically, and an ad-hoc mid-tenancy check keeps its
     * existing lighter-weight lifecycle. Cannot complete while a
     * discrepancy is unresolved either way (§11).
     *
     * This CANNOT become a bypass: outstandingSignatories() and
     * hasAgentSignature() are the same checks RentalInspectionSignature::
     * capture() itself already enforces when creating a row (§15.2a) — a
     * disposition satisfying this guard cannot exist without the real
     * thing (a genuine signature image, or a genuine reason) behind it.
     */
    public function markCompleted(): void
    {
        if ($this->hasUnresolvedDiscrepancy()) {
            throw new \LogicException('Cannot complete an inspection while a discrepancy is unresolved.');
        }

        if (in_array($this->type, [self::TYPE_IN, self::TYPE_OUT], true)) {
            $outstanding = $this->outstandingSignatories();
            if ($outstanding->isNotEmpty()) {
                $first = $outstanding->first();
                if ($first['party_role'] === RentalInspectionSignature::PARTY_TENANT) {
                    // Contact::find() is scope-sensitive (ContactScope) — this
                    // is display only, never the guard itself, so a contact
                    // the completing user's role/agency can't see under its
                    // own scoping just falls back to a generic label rather
                    // than erroring. The BLOCK above (outstandingSignatories())
                    // is unaffected either way: it resolves tenants via
                    // LeaseTenant, not this scoped Contact lookup. Named
                    // precisely by cc1 (2026-09-20) after a real test-fixture
                    // instance of this exact degradation.
                    $name = \App\Models\Contact::find($first['party_contact_id'])?->full_name ?? 'A tenant';
                    throw new \LogicException("Cannot complete: {$name} has neither signed nor been marked as refusing.");
                }
                throw new \LogicException('Cannot complete: the landlord has neither signed nor been marked as refusing.');
            }
            if (! $this->hasAgentSignature()) {
                throw new \LogicException('Cannot complete an inspection without the agent\'s own signature.');
            }
        }

        $completedAt = now();
        $attributes = [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $completedAt,
        ];

        if ($this->type === self::TYPE_IN) {
            $attributes['fault_report_deadline_at'] = $completedAt->copy()
                ->addDays(RentalInspectionSetting::faultReportWindowDaysFor($this->agency_id));
        }

        $this->forceFill($attributes)->save();
    }

    /**
     * §14.1 (mobile-foundation audit, fix 1) — the cancellation transition,
     * pulled out of the web controller so a future API controller can call
     * this exact method instead of re-implementing the same four-field
     * update by hand. This is the ONLY place `status`/`cancelled_at`/
     * `cancelled_by_user_id`/`cancel_reason` are ever set together.
     */
    public function cancel(User $by, string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
            'cancel_reason' => $reason,
        ])->save();
    }

    /**
     * §4/§14.7 — the property-tab's CURRENT inspection of a given type, if
     * one is already under way: the property's active lease's most recent
     * non-completed, non-cancelled inspection of that type. Read-only —
     * deliberately does NOT create one. §0.5 ("inspections are deliberate
     * events, not random acts") rules out silently materialising a real
     * inspection just because an agent opened the tab; see start() for the
     * explicit action that actually begins one.
     */
    public static function currentFor(Property $property, string $type): ?self
    {
        $lease = Lease::where('property_id', $property->id)->where('status', Lease::STATUS_ACTIVE)->first();
        if (! $lease) {
            return null;
        }

        return self::where('lease_id', $lease->id)
            ->where('type', $type)
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED])
            ->latest('id')
            ->first();
    }

    /**
     * The most recent out-inspection ever recorded for this property,
     * regardless of status — including completed — except cancelled (a
     * cancelled attempt never really happened, so it carries no history).
     *
     * Deliberately NOT currentFor(): that method answers "is one currently
     * under way" and correctly excludes completed/cancelled for that
     * question — widening it would break the start()/currentFor() guard
     * against double-starting an inspection. This answers a DIFFERENT
     * question — "which out-inspection's fault history should the tab
     * show" — and the answer to that is needed MOST at the exact moment
     * currentFor() goes null: right after the out-inspection completes,
     * during a deposit dispute. Found via a real QA1 walk on 2026-09-20 —
     * the fault-and-repair block (Stage 5, rental-work-orders.md §3a.5/§6a)
     * was going blank the instant it mattered.
     *
     * Not scoped through the property's ACTIVE lease (unlike currentFor()/
     * start()) — nothing flips a lease's own status on out-inspection
     * completion, and the whole point is to keep working once that lease is
     * no longer active. Scoped through the inspection's own lease_id
     * belonging to this property instead, so the most recent tenancy's
     * out-inspection is found however lease.status reads by then.
     */
    public static function mostRecentOutFor(Property $property): ?self
    {
        return self::mostRecentFor($property, self::TYPE_OUT);
    }

    /**
     * Generic form of mostRecentOutFor() above — the most recent inspection
     * of a given type ever recorded for this property, regardless of
     * status (including completed), except cancelled. Added for §20.15's
     * compare view: the In Inspection being COMPARED against an in-progress
     * Out is, by definition, already completed by then, so the compare
     * view's left side needs this same completed-inclusive lookup that
     * currentFor() (which excludes completed) cannot answer.
     */
    public static function mostRecentFor(Property $property, string $type): ?self
    {
        return self::where('type', $type)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->whereHas('lease', fn ($q) => $q->where('property_id', $property->id))
            ->latest('id')
            ->first();
    }

    /**
     * §20.15 — the RIGHT-hand panel of the two-panel compare view. Johan:
     * "left is in inspection, right is the next inspection (and i state
     * next inspection as it can be ad hoc or out inspection)". Deliberately
     * generic over type (never a literal TYPE_OUT check) so a future
     * ad-hoc inspection slots into this same resolution without a rewrite —
     * TYPE_AD_HOC is not otherwise buildable yet (no start UI/route), but
     * the model constant already exists and this method never needs to
     * know which non-in type it found. Scoped to the SAME lease as the
     * in-inspection it pairs against, so a previous tenancy's leftover
     * out-inspection is never paired against a new tenancy's in-inspection.
     * "Next" = whichever non-in inspection was created FIRST on that lease.
     */
    public static function compareRightFor(Property $property): ?self
    {
        $in = self::mostRecentFor($property, self::TYPE_IN);
        if (! $in) {
            return null;
        }

        return self::where('lease_id', $in->lease_id)
            ->where('type', '!=', self::TYPE_IN)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->oldest('id')
            ->first();
    }

    /**
     * §0.5/§4 — the deliberate action that actually begins an inspection.
     * Refuses if one of this type is already under way for the property's
     * active lease (currentFor() would already have found it — starting a
     * second one is very likely a double-click, not a real second event).
     * TYPE_AD_HOC is exempt from that check: several ad-hoc inspections can
     * legitimately be open at once (§4), so there is no "already one
     * running" concept for it.
     */
    public static function start(Property $property, string $type, User $by): self
    {
        $lease = Lease::where('property_id', $property->id)->where('status', Lease::STATUS_ACTIVE)->first();
        if (! $lease) {
            throw new \LogicException('This property has no active lease — an inspection needs one to attach to.');
        }

        if ($type !== self::TYPE_AD_HOC && self::currentFor($property, $type)) {
            throw new \LogicException(ucfirst($type) . '-inspection is already under way for this tenancy.');
        }

        return self::create([
            'agency_id' => $property->agency_id,
            'lease_id' => $lease->id,
            'type' => $type,
            'created_by_user_id' => $by->id,
            // §17 — "pull what we already know" (Johan): defaulted from the
            // property/lease record so the agent confirms rather than
            // retypes. Deliberately NOT defaulting keys_count/remotes_count/
            // meter readings from anything — those are the whole point of a
            // fresh physical check; pre-filling them would let an agent
            // accept a stale default instead of actually counting.
            'property_type' => $property->property_type,
            'furnished_status' => $property->furnished_status,
            'move_in_date_recorded' => $type === self::TYPE_OUT ? $lease->start_date : null,
        ]);
    }

    /**
     * §17 — the header block, editable any time before the inspection
     * completes (matching how a room's observed condition stays correctable
     * up to that same point) — never after; the completed document is
     * evidence, not a form left open for revision.
     */
    public function updateDetails(array $attributes): void
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            throw new \LogicException('Cannot edit inspection details once the inspection is completed or cancelled.');
        }

        $this->update(array_intersect_key($attributes, array_flip([
            'electricity_meter_reading', 'water_meter_reading',
            'furnished_status', 'property_type',
            'keys_count', 'keys_description',
            'remotes_count', 'remotes_description',
            'move_in_date_recorded',
        ])));
    }

    /**
     * §0.2/§3.2a/§11 — every observation ever recorded against every item on
     * THIS PROPERTY, oldest first, regardless of which lease/tenancy
     * recorded it. This is what an out-inspection view must read: a fault
     * reported under a PREVIOUS tenant stays visible so an owner can't blame
     * the current tenant for damage the owner neglected to fix. Deliberately
     * includes retired items too — retiring only blocks new observations,
     * it never hides history (§3.3).
     */
    public function carryForwardItems(): \Illuminate\Support\Collection
    {
        return RentalInspectionItem::query()
            ->where('property_id', $this->property_id)
            ->with(['observations' => fn (HasMany $q) => $q->oldest('created_at')->oldest('id')])
            ->get();
    }

    /**
     * §14.1/§14.7 — the ONE place "what does the inspection tab need" is
     * resolved: property items plus whichever in/out inspection is
     * currently under way. Called from BOTH the JSON endpoint
     * (RentalInspectionRecordingController::tabData(), what a mobile client
     * fetches) and the property page's own initial server render (so the
     * web tab doesn't pay a second round-trip for data it can render
     * inline) — one resolution, two callers, never two implementations
     * that could drift (§14.1).
     */
    public static function tabPayloadFor(Property $property): array
    {
        // Stage 2 — 'room' is eager-loaded so the (still-flat, pre-Stage-3)
        // recording UI can show "Bedroom 1 — Ceiling" instead of a bare
        // "Ceiling" repeated once per room with nothing to tell them apart.
        $items = RentalInspectionItem::where('property_id', $property->id)
            ->with(['room', 'observations' => fn (HasMany $q) => $q->latest('created_at')])
            ->get();

        // §15.4 — the per-tenant signing UI (Stage 2) needs to know WHO the
        // lease's tenants are to render one row each; lease.tenants.contact
        // is the same relation path already proven elsewhere in this module.
        // §17 — createdBy loaded for the header block's "Inspection done by"
        // display; roomNotes eager-loaded so the tab renders an existing
        // room note without a second round-trip (the frontend resolves
        // "current" as the latest row per room, same pattern as
        // conditionFor() already does for item observations).
        // 2026-09-22 fix — 'discrepancies.item' added: the discrepancy's own
        // direct item() relation, matching the pattern the agency-level list
        // screen's show() already uses ($discrepancy->item?->label — see
        // RentalInspectionController.php). The banner here previously read
        // discrepancy.observations[0]?.item?.label, a separate relation path
        // from 'observations.item' above that never inherited that
        // eager-load, so it was always undefined and rendered the literal
        // string "undefined" (Johan, property 5792). Reading the direct
        // relation is also more robust than depending on an array's first
        // element, and matches the other screen's own approach.
        // 2026-09-22, §20.13 — 'photos' (the whole-inspection tray/room/item
        // photo pool, RentalInspection::photos()) added alongside the
        // existing 'observations.photos' — the latter still serves
        // item-level display unchanged, the former is what the tray and
        // room-level views read from.
        $withDetail = fn (string $type) => self::currentFor($property, $type)
            ?->load(['observations.item', 'observations.photos', 'photos', 'discrepancies.item', 'discrepancies.observations', 'signatures', 'lease.tenants.contact', 'createdBy', 'roomNotes']);

        $outInspection = $withDetail(self::TYPE_OUT);
        // 2026-09-20 fix — deliberately NOT $outInspection above. That value
        // is scoped by currentFor() ("is one currently open"), which goes
        // null the instant an out-inspection completes — exactly the moment
        // the fault history matters most (a deposit dispute after move-out).
        // mostRecentOutFor() answers "which out-inspection's history should
        // the tab show" instead, and keeps answering it after completion.
        $mostRecentOut = self::mostRecentOutFor($property);

        // §20.15 — the two-panel compare view. Both sides deliberately use
        // completed-inclusive lookups (mostRecentFor()/compareRightFor()),
        // same reasoning as $mostRecentOut above: by the time a second
        // inspection exists to compare against, the in-inspection is almost
        // always already completed, and Johan's own framing ("this is
        // evidence in a deposit dispute") means the pair must stay
        // comparable after completion too, not just while in progress.
        $compareRight = self::compareRightFor($property);
        $compareLeft = $compareRight ? self::mostRecentFor($property, self::TYPE_IN) : null;
        $compareDetail = fn (?self $insp) => $insp?->load(['observations.item', 'photos']);
        $compareLeft = $compareDetail($compareLeft);
        $compareRight = $compareDetail($compareRight);

        return [
            'items' => $items,
            'in_inspection' => $withDetail(self::TYPE_IN),
            'out_inspection' => $outInspection,
            // .ai/specs/rental-work-orders.md §3a.5/§6a, Stage 5 — Johan's own
            // reason for this whole feature: "geyser in month 7... an agent
            // can see what damages there were... and what was not repaired."
            // Attached here, not merged — a second query alongside the
            // out-inspection's own data, not a join. Scoped by THIS
            // out-inspection's own lease_id (§3a.4's deliberate contrast with
            // items' own property-wide carry-forward) — empty until an
            // out-inspection actually exists, since there's no lease context
            // to scope by before then.
            'out_inspection_fault_history' => $mostRecentOut
                ? \App\Models\RentalFaultReport::where('lease_id', $mostRecentOut->lease_id)->orderByDesc('reported_at')->get()
                : collect(),
            // §15.4, Stage 3 — property-level (unlike tenants, which are
            // lease-level), so both in_inspection and out_inspection share
            // this same value. Null when Property::sellerOwnerContact()
            // can't resolve one — the UI shows that plainly (§15.4) rather
            // than hiding the row or blocking on a party nobody can name.
            'landlord_contact' => $property->sellerOwnerContact(),
            // §15.5/§15.6, Stage 4 — the one-tap reason list the refusal
            // form picks from. 'other' always present and always last,
            // regardless of what the agency has saved (enforced inside
            // refusalReasonPresetsFor() itself, not here).
            'refusal_reason_presets' => \App\Models\RentalInspectionSetting::refusalReasonPresetsFor($property->agency_id),
            // Drives the fault-history block's own visibility on the tab —
            // deliberately separate from out_inspection (above) so the block
            // stays visible once out_inspection goes null on completion.
            'out_inspection_recorded' => (bool) $mostRecentOut,
            // §17, Johan 2026-09-21, from Retha's real paper out-inspection
            // form — the agency's own condition vocabulary (Good/Fair/
            // Damaged/Not working/Missing/Other/N/A by default), rendered
            // into the recording UI's condition picker instead of a
            // hardcoded set of <option> tags.
            'condition_states' => \App\Models\RentalInspectionSetting::conditionStatesFor($property->agency_id),
            // §20.15 — null/null when there is nothing yet to compare (only
            // an in-inspection exists so far, the common case); the compare
            // UI is gated entirely on compare_right_inspection being present.
            'compare_left_inspection' => $compareLeft,
            'compare_right_inspection' => $compareRight,
            'photo_matches' => ($compareLeft && $compareRight)
                ? \App\Models\RentalInspectionPhotoMatch::with(['photoA', 'photoB'])
                    ->where(function ($q) use ($compareLeft, $compareRight) {
                        $ids = [$compareLeft->id, $compareRight->id];
                        $q->whereHas('photoA', fn ($qq) => $qq->whereIn('rental_inspection_id', $ids))
                            ->orWhereHas('photoB', fn ($qq) => $qq->whereIn('rental_inspection_id', $ids));
                    })
                    ->get()
                : collect(),
        ];
    }
}

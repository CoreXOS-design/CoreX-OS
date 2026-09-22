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

    public function markCompleted(): void
    {
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

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inventory.md §5 — "every page is signed" (Johan).
 * Deliberately mirrors RentalInspectionSignature's capture() invariants —
 * see that class's own docblock for the reasoning; not re-derived here.
 * A genuinely separate table (see the migration's own docblock for why a
 * polymorphic merge was considered and rejected for tonight).
 */
class RentalInventorySignature extends Model
{
    use BelongsToAgency;

    public const PARTY_TENANT = 'tenant';
    public const PARTY_LANDLORD = 'landlord';
    public const PARTY_AGENT = 'agent';

    public const DISPOSITION_SIGNED = 'signed';
    public const DISPOSITION_REFUSED = 'refused';

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'party_role',
        'party_contact_id',
        'disposition',
        'party_signature_path',
        'refusal_reason_preset',
        'refusal_reason_note',
        'recorded_by_user_id',
        'disposition_recorded_at',
    ];

    protected $casts = [
        'disposition_recorded_at' => 'datetime',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(RentalInventory::class, 'rental_inventory_id');
    }

    public function partyContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'party_contact_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** Same invariant set as RentalInspectionSignature::capture() — see that method's own docblock. */
    public static function capture(RentalInventory $inventory, string $partyRole, string $disposition, array $attributes = []): self
    {
        if (! in_array($partyRole, [self::PARTY_TENANT, self::PARTY_LANDLORD, self::PARTY_AGENT], true)) {
            throw new \InvalidArgumentException("Unknown party_role: {$partyRole}");
        }
        if (! in_array($disposition, [self::DISPOSITION_SIGNED, self::DISPOSITION_REFUSED], true)) {
            throw new \InvalidArgumentException("Unknown disposition: {$disposition}");
        }

        if ($partyRole === self::PARTY_AGENT) {
            if ($disposition !== self::DISPOSITION_SIGNED) {
                throw new \InvalidArgumentException('The agent has no refusal option — an agent row is always signed.');
            }
            if (empty($attributes['party_signature_path'])) {
                throw new \InvalidArgumentException("The agent's own signature image (party_signature_path) is required.");
            }
            if ($inventory->hasAgentSignature()) {
                throw new \LogicException('The agent has already signed this inventory.');
            }
            if ($inventory->outstandingSignatories()->isNotEmpty()) {
                throw new \LogicException('Cannot record the agent\'s signature until every tenant and the landlord has a disposition recorded.');
            }
            $attributes['party_contact_id'] = null;
            $attributes['refusal_reason_preset'] = null;
            $attributes['refusal_reason_note'] = null;
        } else {
            $contactId = $attributes['party_contact_id'] ?? null;
            if (empty($contactId)) {
                throw new \InvalidArgumentException("A {$partyRole} disposition requires party_contact_id.");
            }

            if ($partyRole === self::PARTY_TENANT) {
                $isLeaseTenant = LeaseTenant::where('lease_id', $inventory->lease_id)
                    ->where('contact_id', $contactId)->exists();
                if (! $isLeaseTenant) {
                    throw new \InvalidArgumentException('party_contact_id is not a tenant on this inventory\'s own lease.');
                }
            } else { // landlord
                $landlordContactId = $inventory->property?->sellerOwnerContact()?->id;
                if (! $landlordContactId || (int) $landlordContactId !== (int) $contactId) {
                    throw new \InvalidArgumentException('party_contact_id does not match this property\'s resolved landlord contact.');
                }
            }

            $alreadyDispositioned = $inventory->signatures()
                ->where('party_role', $partyRole)
                ->where('party_contact_id', $contactId)
                ->exists();
            if ($alreadyDispositioned) {
                throw new \LogicException('This party already has a disposition recorded on this inventory.');
            }

            if ($disposition === self::DISPOSITION_SIGNED) {
                if (empty($attributes['party_signature_path'])) {
                    throw new \InvalidArgumentException('A signed disposition requires party_signature_path.');
                }
                $attributes['refusal_reason_preset'] = null;
                $attributes['refusal_reason_note'] = null;
            } else { // refused
                if (! empty($attributes['party_signature_path'])) {
                    throw new \InvalidArgumentException('A refused disposition must not carry a signature image.');
                }
                if (empty($attributes['refusal_reason_preset'])) {
                    throw new \InvalidArgumentException('A refused disposition requires refusal_reason_preset.');
                }
                if ($attributes['refusal_reason_preset'] === 'other' && empty($attributes['refusal_reason_note'])) {
                    throw new \InvalidArgumentException('refusal_reason_preset "other" requires refusal_reason_note.');
                }
                $attributes['party_signature_path'] = null;
            }
        }

        return self::create(array_merge($attributes, [
            'agency_id' => $inventory->agency_id,
            'rental_inventory_id' => $inventory->id,
            'party_role' => $partyRole,
            'disposition' => $disposition,
            'disposition_recorded_at' => $attributes['disposition_recorded_at'] ?? now(),
        ]));
    }

    /** Same canvas-capture storage pattern as RentalInspectionSignature::storeCanvasImage(). */
    public static function storeCanvasImage(string $base64, int $propertyId): string
    {
        $data = str_contains($base64, ',') ? explode(',', $base64, 2)[1] : $base64;
        $binary = base64_decode($data, true) ?: '';

        $path = "properties/{$propertyId}/rental-inventory-signatures/" . uniqid('sig_', true) . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $binary);

        return \Illuminate\Support\Facades\Storage::url($path);
    }
}

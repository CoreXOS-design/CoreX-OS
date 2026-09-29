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
 *
 * Conductor brief 2026-09-29 — wet-ink signing, built here for the first
 * time (mirrors rental-inspections.md §16/§17's already-built shape
 * exactly, including the awaiting_wet_ink pre-state — see
 * RentalInspectionSignature's own docblocks for the full reasoning, not
 * re-derived here).
 */
class RentalInventorySignature extends Model
{
    use BelongsToAgency;

    public const PARTY_TENANT = 'tenant';
    public const PARTY_LANDLORD = 'landlord';
    /** §22 ruling (Johan, 2026-09-29) — the owner's real role on a sale property-level inventory. See RentalInventory::ownerPartyRole(). */
    public const PARTY_SELLER = 'seller';
    public const PARTY_AGENT = 'agent';

    public const DISPOSITION_SIGNED = 'signed';
    public const DISPOSITION_REFUSED = 'refused';
    /** A tenant or landlord who signed on paper, not on the agent's device. Never valid for party_role=agent. */
    public const DISPOSITION_WET_INK = 'wet_ink';
    /** The paper has been sent out; nothing has arrived yet. A tracking marker only, never a completing disposition. */
    public const DISPOSITION_AWAITING_WET_INK = 'awaiting_wet_ink';

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'party_role',
        'party_contact_id',
        'disposition',
        'party_signature_path',
        'wet_ink_upload_path',
        'refusal_reason_preset',
        'refusal_reason_note',
        'recorded_by_user_id',
        'disposition_recorded_at',
        'superseded_at',
        'superseded_by_signature_id',
    ];

    protected $casts = [
        'disposition_recorded_at' => 'datetime',
        'superseded_at' => 'datetime',
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

    /** The row this one was replaced by, if any. Never null'd out; the chain is the record. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_signature_id');
    }

    public function isWetInk(): bool
    {
        return $this->disposition === self::DISPOSITION_WET_INK;
    }

    public function isAwaitingWetInk(): bool
    {
        return $this->disposition === self::DISPOSITION_AWAITING_WET_INK;
    }

    /** Either wet-ink state — the two dispositions supersedeWetInk() below can replace. */
    public function isReplaceableWetInk(): bool
    {
        return $this->isWetInk() || $this->isAwaitingWetInk();
    }

    /** Same invariant set as RentalInspectionSignature::capture() — see that method's own docblock. */
    public static function capture(RentalInventory $inventory, string $partyRole, string $disposition, array $attributes = []): self
    {
        if (! in_array($partyRole, [self::PARTY_TENANT, self::PARTY_LANDLORD, self::PARTY_SELLER, self::PARTY_AGENT], true)) {
            throw new \InvalidArgumentException("Unknown party_role: {$partyRole}");
        }
        if (! in_array($disposition, [self::DISPOSITION_SIGNED, self::DISPOSITION_REFUSED, self::DISPOSITION_WET_INK, self::DISPOSITION_AWAITING_WET_INK], true)) {
            throw new \InvalidArgumentException("Unknown disposition: {$disposition}");
        }

        if ($partyRole === self::PARTY_AGENT) {
            if ($disposition !== self::DISPOSITION_SIGNED) {
                throw new \InvalidArgumentException('The agent has no refusal option, and is never wet-ink — an agent row is always signed.');
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
            // Conductor brief 2026-09-29 — a party marked awaiting_wet_ink
            // DOES have a live row, so outstandingSignatories() above never
            // catches them; they still have no actual evidence yet, so the
            // agent cannot sign until the scan arrives.
            if ($inventory->firstAwaitingWetInkSignatory()) {
                throw new \LogicException('Cannot record the agent\'s signature until every party\'s wet-ink upload has arrived.');
            }
            $attributes['party_contact_id'] = null;
            $attributes['wet_ink_upload_path'] = null;
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
            } else { // landlord or seller — the property's one owner-side party
                if ($partyRole !== $inventory->ownerPartyRole()) {
                    // §22 ruling — a sale inventory's owner signs as SELLER,
                    // never LANDLORD, and vice versa for a rental one; this
                    // stops the wrong role being recorded even if a stale
                    // client sends it.
                    throw new \InvalidArgumentException("party_role '{$partyRole}' does not match this inventory's resolved owner role ('{$inventory->ownerPartyRole()}').");
                }
                $ownerContactId = $inventory->property?->sellerOwnerContact()?->id;
                if (! $ownerContactId || (int) $ownerContactId !== (int) $contactId) {
                    throw new \InvalidArgumentException('party_contact_id does not match this property\'s resolved owner contact.');
                }
            }

            // A superseded row is a corrected mistake, not a live
            // disposition; it must not block the replacement it exists to
            // make room for — same reasoning as RentalInspectionSignature's
            // own capture().
            $alreadyDispositioned = $inventory->signatures()
                ->where('party_role', $partyRole)
                ->where('party_contact_id', $contactId)
                ->whereNull('superseded_at')
                ->exists();
            if ($alreadyDispositioned) {
                throw new \LogicException('This party already has a disposition recorded on this inventory.');
            }

            if ($disposition === self::DISPOSITION_SIGNED) {
                if (empty($attributes['party_signature_path'])) {
                    throw new \InvalidArgumentException('A signed disposition requires party_signature_path.');
                }
                $attributes['wet_ink_upload_path'] = null;
                $attributes['refusal_reason_preset'] = null;
                $attributes['refusal_reason_note'] = null;
            } elseif ($disposition === self::DISPOSITION_REFUSED) {
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
                $attributes['wet_ink_upload_path'] = null;
            } elseif ($disposition === self::DISPOSITION_WET_INK) { // evidence of a real signature, on paper, never rendered as an e-signature
                if (empty($attributes['wet_ink_upload_path'])) {
                    throw new \InvalidArgumentException('A wet-ink disposition requires wet_ink_upload_path.');
                }
                if (! empty($attributes['party_signature_path'])) {
                    throw new \InvalidArgumentException('A wet-ink disposition must not carry a canvas signature image — the two capture methods are never mixed on one row.');
                }
                $attributes['party_signature_path'] = null;
                $attributes['refusal_reason_preset'] = null;
                $attributes['refusal_reason_note'] = null;
            } else { // awaiting_wet_ink — sent to be signed on paper; nothing has arrived yet
                if (! empty($attributes['wet_ink_upload_path']) || ! empty($attributes['party_signature_path'])) {
                    throw new \InvalidArgumentException('An awaiting-wet-ink disposition carries no upload and no signature image yet — those arrive via the supersede-wet-ink upload once the scan comes back.');
                }
                $attributes['party_signature_path'] = null;
                $attributes['wet_ink_upload_path'] = null;
                $attributes['refusal_reason_preset'] = null;
                $attributes['refusal_reason_note'] = null;
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

    /** Same real-file storage pattern as RentalInspectionSignature::storeWetInkUpload(). */
    public static function storeWetInkUpload(\Illuminate\Http\UploadedFile $file, int $propertyId): string
    {
        $filename = uniqid('wetink_', true) . '.' . ($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs("properties/{$propertyId}/rental-inventory-signatures", $filename, 'public');

        return \Illuminate\Support\Facades\Storage::url($path);
    }

    /**
     * Same supersede mechanism as RentalInspectionSignature::supersedeWetInk()
     * — correcting a wrong/unreadable upload, AND resolving an
     * awaiting_wet_ink row the first time a scan actually arrives (the same
     * transition, two different starting points). Never edited in place,
     * never destroyed (non-negotiable #1).
     */
    public static function supersedeWetInk(self $existing, RentalInventory $inventory, \Illuminate\Http\UploadedFile $file, ?int $recordedByUserId): self
    {
        if (! $existing->isReplaceableWetInk()) {
            throw new \LogicException('Only a wet-ink or awaiting-wet-ink disposition can be superseded this way — a signed or refused disposition is not corrected by re-upload.');
        }
        if ($existing->superseded_at !== null) {
            throw new \LogicException('This wet-ink upload has already been superseded.');
        }
        if ($inventory->hasAgentSignature()) {
            throw new \LogicException('Cannot replace a wet-ink upload once the agent has signed — the agent\'s signature already attests to this record as it stood.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($existing, $inventory, $file, $recordedByUserId) {
            $existing->forceFill(['superseded_at' => now()])->save();

            $replacement = self::capture($inventory, $existing->party_role, self::DISPOSITION_WET_INK, [
                'party_contact_id' => $existing->party_contact_id,
                'wet_ink_upload_path' => self::storeWetInkUpload($file, $inventory->property_id),
                'recorded_by_user_id' => $recordedByUserId,
            ]);

            $existing->forceFill(['superseded_by_signature_id' => $replacement->id])->save();

            return $replacement;
        });
    }
}

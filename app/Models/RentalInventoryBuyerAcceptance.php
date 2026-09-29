<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §24 ruling (Johan, 2026-09-29) — the buyer's own "signature of
 * acceptance" of what they get in the sale, captured AFTER the inventory
 * is already completed and filed at mandate stage. Deliberately a separate
 * table from `RentalInventorySignature`: that model's whole shape
 * (outstandingSignatories(), the completion gate) is about who must sign
 * BEFORE an inventory can complete; this is an optional, later,
 * additive step that never reopens or edits the completed record — no
 * code path here ever calls RentalInventory::assertEditable().
 *
 * Two capture methods only (Johan's own words: "on screen or wet-ink") —
 * no refusal disposition, no awaiting_wet_ink tracking marker. This is not
 * a gate anything blocks on, so there is nothing for an agent to "mark as
 * outstanding" the way a signature refusal is.
 */
class RentalInventoryBuyerAcceptance extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const DISPOSITION_SIGNED = 'signed';
    public const DISPOSITION_WET_INK = 'wet_ink';

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'buyer_contact_id',
        'disposition',
        'party_signature_path',
        'wet_ink_upload_path',
        'recorded_by_user_id',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(RentalInventory::class, 'rental_inventory_id');
    }

    public function buyerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @param  array{party_signature_path?: string, wet_ink_upload_path?: string, recorded_by_user_id?: ?int}  $attributes
     *
     * @throws \LogicException|\InvalidArgumentException
     */
    public static function capture(RentalInventory $inventory, Contact $buyer, string $disposition, array $attributes = []): self
    {
        if (! $inventory->buyerAcceptanceOfferedFor()) {
            throw new \LogicException('Buyer acceptance is not offered on this inventory — it requires a completed sale-property inventory with a committed (accepted-offer) deal.');
        }

        $isEligibleBuyer = $inventory->eligibleBuyerContacts()->contains(fn (Contact $c) => (int) $c->id === (int) $buyer->id);
        if (! $isEligibleBuyer) {
            throw new \InvalidArgumentException('This contact is not a buyer on the property\'s current committed deal.');
        }

        if (! in_array($disposition, [self::DISPOSITION_SIGNED, self::DISPOSITION_WET_INK], true)) {
            throw new \InvalidArgumentException("Unknown disposition: {$disposition}");
        }

        $alreadyAccepted = $inventory->buyerAcceptances()
            ->where('buyer_contact_id', $buyer->id)
            ->exists();
        if ($alreadyAccepted) {
            throw new \LogicException('This buyer has already recorded their acceptance of this inventory.');
        }

        if ($disposition === self::DISPOSITION_SIGNED) {
            if (empty($attributes['party_signature_path'])) {
                throw new \InvalidArgumentException('A signed disposition requires party_signature_path.');
            }
            $attributes['wet_ink_upload_path'] = null;
        } else { // wet_ink
            if (empty($attributes['wet_ink_upload_path'])) {
                throw new \InvalidArgumentException('A wet-ink disposition requires wet_ink_upload_path.');
            }
            $attributes['party_signature_path'] = null;
        }

        return self::create(array_merge($attributes, [
            'agency_id' => $inventory->agency_id,
            'rental_inventory_id' => $inventory->id,
            'buyer_contact_id' => $buyer->id,
            'disposition' => $disposition,
            'accepted_at' => now(),
        ]));
    }

    /** Same canvas-capture storage pattern as RentalInventorySignature::storeCanvasImage(), own folder. */
    public static function storeCanvasImage(string $base64, int $propertyId): string
    {
        $data = str_contains($base64, ',') ? explode(',', $base64, 2)[1] : $base64;
        $binary = base64_decode($data, true) ?: '';

        $path = "properties/{$propertyId}/rental-inventory-buyer-acceptances/" . uniqid('sig_', true) . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $binary);

        return \Illuminate\Support\Facades\Storage::url($path);
    }

    /** Same real-file storage pattern as RentalInventorySignature::storeWetInkUpload(), own folder. */
    public static function storeWetInkUpload(\Illuminate\Http\UploadedFile $file, int $propertyId): string
    {
        $filename = uniqid('wetink_', true) . '.' . ($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs("properties/{$propertyId}/rental-inventory-buyer-acceptances", $filename, 'public');

        return \Illuminate\Support\Facades\Storage::url($path);
    }
}

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
        self::assertCanCapture($inventory, $buyer, $disposition);

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

    /**
     * The eligibility checks capture() enforces, callable on their own so the
     * public route can refuse BEFORE it writes any uploaded file (audit
     * A-H1: a rejected attempt used to leave its file on disk).
     *
     * @throws \LogicException|\InvalidArgumentException
     */
    public static function assertCanCapture(RentalInventory $inventory, Contact $buyer, string $disposition): void
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
    }

    /**
     * Same canvas-capture storage pattern as RentalInventorySignature::storeCanvasImage(), own folder.
     * Audit A-H1 (2026-10-18) — this is reached by an unauthenticated visitor, so the decoded bytes must
     * really be a PNG within the size cap (re-encoded so only pixels are written), not whatever was posted.
     *
     * @throws \InvalidArgumentException when the payload is not a usable PNG
     */
    public static function storeCanvasImage(string $base64, int $propertyId): string
    {
        $binary = \App\Support\PrivateScanFile::validatedPng($base64);

        // Private disk, referenced as "private:<disk path>" (never a web-readable /storage URL) — see
        // .ai/specs/private-document-storage.md. Read back through signature_image_src.
        $path = "rental-inventory-buyer-acceptances/{$propertyId}/sig_" . bin2hex(random_bytes(16)) . '.png';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $binary);

        return \App\Support\PrivateScanFile::PRIVATE_PREFIX . $path;
    }

    /** A self-contained data: URI for the stored signature (private or legacy public reference); null when the file is missing. */
    public function getSignatureImageSrcAttribute(): ?string
    {
        return \App\Support\StorageDataUri::fromPublicStoragePath($this->party_signature_path);
    }

    /** Audit A-H1 — wet-ink scans are stored on the PRIVATE disk and referenced as "private:<disk path>"; legacy rows hold a public "/storage/..." URL. */
    public const PRIVATE_PREFIX = \App\Support\PrivateScanFile::PRIVATE_PREFIX;

    /**
     * Audit A-H1 (2026-10-18) — this is reached by an unauthenticated
     * visitor, so the file used to land on the public disk under the
     * browser-supplied extension. Now: private disk, server-generated name,
     * extension from the sniffed MIME via a fixed allow-list, served only
     * through wetInkFileResponse() behind an own/branch-checked agent route.
     *
     * @throws \InvalidArgumentException when the file type is not allowed
     */
    public static function storeWetInkUpload(\Illuminate\Http\UploadedFile $file, int $propertyId): string
    {
        return \App\Support\PrivateScanFile::store($file, "rental-inventory-buyer-acceptances/{$propertyId}");
    }

    /** Remove a just-stored private scan again (used when the acceptance it was uploaded for is then refused). */
    public static function discardStoredWetInk(?string $stored): void
    {
        \App\Support\PrivateScanFile::discard($stored);
    }

    /** The URL the agent's inventory page links for the wet-ink scan: the checked route for a private file, the stored URL as-is for a legacy public one. */
    public function wetInkFileUrl(): ?string
    {
        $stored = $this->wet_ink_upload_path;
        if (! $stored) {
            return null;
        }
        if (! str_starts_with($stored, self::PRIVATE_PREFIX)) {
            return $stored;
        }

        return route('corex.rental-inventories.buyer-acceptances.wet-ink-file', [
            'rentalInventory' => $this->rental_inventory_id,
            'acceptance' => $this->id,
        ]);
    }

    /** Stream the wet-ink scan (nosniff, sandbox CSP, image/PDF types only — see PrivateScanFile::response()). */
    public function wetInkFileResponse(): \Symfony\Component\HttpFoundation\Response
    {
        return \App\Support\PrivateScanFile::response($this->wet_ink_upload_path);
    }
}

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

    /** Same canvas-capture storage pattern as RentalInventorySignature::storeCanvasImage(), own folder. */
    public static function storeCanvasImage(string $base64, int $propertyId): string
    {
        $data = str_contains($base64, ',') ? explode(',', $base64, 2)[1] : $base64;
        $binary = base64_decode($data, true) ?: '';

        $path = "properties/{$propertyId}/rental-inventory-buyer-acceptances/" . uniqid('sig_', true) . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $binary);

        return \Illuminate\Support\Facades\Storage::url($path);
    }

    /** Audit A-H1 — wet-ink scans are stored on the PRIVATE disk and referenced as "private:<disk path>"; legacy rows hold a public "/storage/..." URL. */
    public const PRIVATE_PREFIX = 'private:';

    /** Content-sniffed MIME -> the ONLY extension we ever write (the client's filename/extension is never used). */
    private const WET_INK_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
    ];

    /**
     * Audit A-H1 (2026-10-18) — this is reached by an unauthenticated
     * visitor, so the file used to land on the public disk under the
     * browser-supplied extension (a PNG-header file named x.html was then
     * served by nginx as HTML on our own domain). Now: private disk,
     * server-generated name, extension from the sniffed MIME via a fixed
     * allow-list, served only through fileResponse() behind an
     * own/branch-checked agent route. Same shape as
     * RentalInspectionSignature::storeWetInkUpload().
     *
     * @throws \InvalidArgumentException when the file type is not allowed
     */
    public static function storeWetInkUpload(\Illuminate\Http\UploadedFile $file, int $propertyId): string
    {
        $extension = self::WET_INK_EXTENSIONS[strtolower((string) $file->getMimeType())] ?? null;
        if ($extension === null) {
            throw new \InvalidArgumentException('The uploaded page must be a PDF, JPG, PNG or HEIC file.');
        }

        $filename = 'wetink_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $file->storeAs("rental-inventory-buyer-acceptances/{$propertyId}", $filename, 'local');
        if ($path === false) {
            throw new \LogicException('The uploaded page could not be stored.');
        }

        return self::PRIVATE_PREFIX . $path;
    }

    /** Remove a just-stored private scan again (used when the acceptance it was uploaded for is then refused). */
    public static function discardStoredWetInk(?string $stored): void
    {
        if ($stored && str_starts_with($stored, self::PRIVATE_PREFIX)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete(substr($stored, strlen(self::PRIVATE_PREFIX)));
        }
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

    /**
     * Stream the wet-ink scan. Only image/PDF types are ever served inline,
     * with nosniff + a sandboxing CSP — exactly RentalInspectionSignature::
     * fileResponse(), so an old public-era upload with a hostile payload
     * cannot execute as a page either.
     */
    public function wetInkFileResponse(): \Symfony\Component\HttpFoundation\Response
    {
        $read = RentalInspectionSignature::readStored($this->wet_ink_upload_path);
        abort_if($read === null, 404);

        [$bytes, $mime] = $read;
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/heic', 'image/heif', 'application/pdf'], true), 404);

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ];
        // A PDF must NOT get the sandbox CSP (Chrome's PDF viewer will not render inside it); an
        // application/pdf body with nosniff cannot execute as a page.
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
        }

        return response($bytes, 200, $headers);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §15 — Johan's 2026-09-20 fuller ruling:
 * three party roles (tenant, landlord, agent) on BOTH inspection types,
 * with tenant/landlord refusal as a first-class disposition, never an
 * absent signature. Replaces the tenant-only, out-inspection-only shape
 * this class previously had.
 *
 * `disposition` is the ONE column anything reading a row must branch on —
 * never `party_role` alone, never whether `party_signature_path` happens to
 * be null. An agent row is always disposition='signed'; there is no
 * refusal option for the agent (Johan, verbatim) — enforced in capture()
 * below, never left to a caller to get right.
 */
class RentalInspectionSignature extends Model
{
    use BelongsToAgency;

    public const PARTY_TENANT = 'tenant';
    public const PARTY_LANDLORD = 'landlord';
    public const PARTY_AGENT = 'agent';

    public const DISPOSITION_SIGNED = 'signed';
    public const DISPOSITION_REFUSED = 'refused';
    /**
     * .ai/specs/rental-inspections.md §16 — a tenant or landlord who signed
     * on paper, not on the agent's device. Never valid for party_role=agent
     * (the agent is always present, always §15's live canvas capture).
     * Evidence of a real signature, but never presentable as one on screen —
     * same principle as a refusal never being presentable as a signature.
     */
    public const DISPOSITION_WET_INK = 'wet_ink';
    /**
     * Conductor brief 2026-09-29 — "the agent marks a party as sent [for a
     * paper signature]. The screen shows who is outstanding." The scan
     * hasn't arrived yet at this point — no upload, no signature image, no
     * refusal. A tracking marker only, never a completing disposition:
     * RentalInspection::markCompleted() refuses to complete while any party
     * is still in this state (see that method's own guard). Never valid for
     * party_role=agent, same as DISPOSITION_WET_INK. Resolved into
     * DISPOSITION_WET_INK by supersedeWetInk() once the scan actually
     * arrives — the SAME transition already used to correct a wrong
     * wet-ink upload, just from a different starting disposition.
     */
    public const DISPOSITION_AWAITING_WET_INK = 'awaiting_wet_ink';

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
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

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function partyContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'party_contact_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** §16 — the row this one was replaced by, if any. Never null'd out; the chain is the record. */
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

    /**
     * §15.2a — the ONE place every invariant in this table is enforced, so
     * no caller (web controller today, a future mobile API controller
     * tomorrow — §15.10) can create a row that violates them. Every
     * exception here is deliberate and load-bearing:
     *
     * - An agent row is always 'signed', requires its own signature image,
     *   and can only be created once every tenant and the landlord already
     *   has a disposition — a signature cannot attest to a refusal that
     *   hasn't happened yet (§15.2a).
     * - A tenant/landlord row requires a real, matching party_contact_id
     *   (§15.4's per-party rule) and cannot be recorded twice for the same
     *   party on the same inspection.
     * - A 'signed' row requires a signature image and carries no refusal
     *   fields; a 'refused' row requires a reason and carries no signature
     *   image — never both, never neither.
     */
    public static function capture(RentalInspection $inspection, string $partyRole, string $disposition, array $attributes = []): self
    {
        // Audit H1 — a completed / cancelled / archived inspection takes no
        // further signatures (the signed record must not change after the fact).
        $inspection->assertRecordable();

        if (! in_array($partyRole, [self::PARTY_TENANT, self::PARTY_LANDLORD, self::PARTY_AGENT], true)) {
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
            if ($inspection->hasAgentSignature()) {
                throw new \LogicException('The agent has already signed this inspection.');
            }
            if ($inspection->outstandingSignatories()->isNotEmpty()) {
                throw new \LogicException('Cannot record the agent\'s signature until every tenant and the landlord has a disposition recorded — the agent\'s signature attests to the complete record, not a partial one.');
            }
            // Conductor brief 2026-09-29 — a party marked awaiting_wet_ink
            // DOES have a live row, so outstandingSignatories() above never
            // catches them; they still have no actual evidence yet, so the
            // agent (who attests to the complete record) cannot sign until
            // the scan arrives.
            if ($inspection->firstAwaitingWetInkSignatory()) {
                throw new \LogicException('Cannot record the agent\'s signature until every party\'s wet-ink upload has arrived — the agent\'s signature attests to the complete record, not a partial one.');
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
                $isLeaseTenant = LeaseTenant::where('lease_id', $inspection->lease_id)
                    ->where('contact_id', $contactId)->exists();
                if (! $isLeaseTenant) {
                    throw new \InvalidArgumentException('party_contact_id is not a tenant on this inspection\'s own lease.');
                }
            } else { // landlord
                $landlordContactId = $inspection->property?->sellerOwnerContact()?->id;
                if (! $landlordContactId || (int) $landlordContactId !== (int) $contactId) {
                    throw new \InvalidArgumentException('party_contact_id does not match this property\'s resolved landlord contact.');
                }
            }

            // §16 — a superseded row is a corrected mistake, not a live
            // disposition; it must not block the replacement it exists to
            // make room for. supersedeWetInk() below is the only caller
            // that creates a new row while an old one for the same party
            // already exists, and it marks the old row superseded FIRST,
            // inside the same transaction, so this check never race-allows
            // two live rows for one party.
            $alreadyDispositioned = $inspection->signatures()
                ->where('party_role', $partyRole)
                ->where('party_contact_id', $contactId)
                ->whereNull('superseded_at')
                ->exists();
            if ($alreadyDispositioned) {
                throw new \LogicException('This party already has a disposition recorded on this inspection.');
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
                    throw new \InvalidArgumentException('A refused disposition must not carry a signature image — that is what makes it unmistakably not a signature.');
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
            'agency_id' => $inspection->agency_id,
            'rental_inspection_id' => $inspection->id,
            'party_role' => $partyRole,
            'disposition' => $disposition,
            'disposition_recorded_at' => $attributes['disposition_recorded_at'] ?? now(),
        ]));
    }

    /**
     * Audit M4 — signature images and wet-ink documents are stored on the
     * PRIVATE ('local') disk and referenced as "private:<disk path>". They
     * are served only through an authorised route (agent screens: session +
     * scoping; public report page: a valid, unexpired token) — see fileUrl()
     * and fileResponse(). Rows written before this change hold the old
     * public "/storage/..." URL form; those are still read from the public
     * disk (both forms are handled everywhere a stored path is read).
     */
    public const PRIVATE_PREFIX = 'private:';

    /** Wet-ink evidence: content-sniffed MIME -> the ONLY extension we ever write. */
    private const WET_INK_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
    ];

    /** Decoded-size ceiling for a canvas signature PNG. */
    private const MAX_CANVAS_BYTES = 1048576;

    /**
     * §3.6/§14.1 — decode a canvas-captured signature (base64 PNG) and store
     * it, returning the stored reference to save as party_signature_path.
     * Pulled out of the controller deliberately: a future mobile API
     * controller calls this exact method instead of re-implementing it
     * (§15.10).
     *
     * Audit M5 — strict base64, non-empty, size-capped, and the bytes must
     * really be a PNG (getimagesizefromstring); the image is re-encoded
     * through GD when available so nothing but pixel data is ever written.
     *
     * @throws \InvalidArgumentException when the payload is not a usable PNG
     */
    public static function storeCanvasImage(string $base64, int $propertyId): string
    {
        $data = str_contains($base64, ',') ? explode(',', $base64, 2)[1] : $base64;
        $binary = base64_decode(trim($data), true);

        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('The signature image is empty or not valid base64.');
        }
        if (strlen($binary) > self::MAX_CANVAS_BYTES) {
            throw new \InvalidArgumentException('The signature image is too large.');
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new \InvalidArgumentException('The signature must be a PNG image.');
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $image = @imagecreatefromstring($binary);
            if ($image === false) {
                throw new \InvalidArgumentException('The signature image could not be read.');
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $reencoded = (string) ob_get_clean();
            imagedestroy($image);
            if ($reencoded === '') {
                throw new \InvalidArgumentException('The signature image could not be processed.');
            }
            $binary = $reencoded;
        }

        // No EXIF-orientation step — unlike a phone camera photo, a
        // canvas-drawn signature has no camera orientation to correct.
        $path = "rental-inspection-signatures/{$propertyId}/" . bin2hex(random_bytes(16)) . '.png';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $binary);

        return self::PRIVATE_PREFIX . $path;
    }

    /**
     * §16 — a photo or scan of a page a tenant or landlord signed on paper.
     * Deliberately a real file upload, not base64-JSON like
     * storeCanvasImage() — this is a document someone photographed or
     * scanned, not a canvas drawing, so it arrives as multipart form data.
     *
     * Audit H3 — the client's filename/extension is NEVER used. The
     * extension comes from the content-sniffed MIME type through a fixed
     * allow-list, and the filename is generated server-side.
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
        $path = $file->storeAs("rental-inspection-signatures/{$propertyId}", $filename, 'local');
        if ($path === false) {
            throw new \LogicException('The uploaded page could not be stored.');
        }

        return self::PRIVATE_PREFIX . $path;
    }

    /**
     * The URL a screen should use for this row's stored file. A legacy
     * public-disk value is returned as-is; a private one goes through an
     * authorised route: the agent-side one by default, or — when $publicToken
     * is given (the public report page) — the token-authorised one.
     *
     * @param 'signature'|'wet-ink' $kind
     */
    public function fileUrl(string $kind, ?string $publicToken = null): ?string
    {
        $stored = $kind === 'wet-ink' ? $this->wet_ink_upload_path : $this->party_signature_path;
        if (! $stored) {
            return null;
        }
        if (! str_starts_with($stored, self::PRIVATE_PREFIX)) {
            return $stored;
        }

        return $publicToken
            ? route('rental-inspections.public.signature-file', ['token' => $publicToken, 'signature' => $this->id, 'kind' => $kind])
            : route('corex.rental-inspections.signatures.file', ['rentalInspection' => $this->rental_inspection_id, 'signature' => $this->id, 'kind' => $kind]);
    }

    /**
     * Read a stored reference (private or legacy public) back to bytes.
     *
     * @return array{0:string,1:string}|null [bytes, mime]
     */
    public static function readStored(?string $stored): ?array
    {
        if (! $stored) {
            return null;
        }
        if (str_starts_with($stored, self::PRIVATE_PREFIX)) {
            $disk = \Illuminate\Support\Facades\Storage::disk('local');
            $relative = substr($stored, strlen(self::PRIVATE_PREFIX));
        } else {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            $relative = preg_replace('#^.*/storage/#', '', $stored);
        }
        if (! $relative || str_contains($relative, '..') || ! $disk->exists($relative)) {
            return null;
        }
        $bytes = $disk->get($relative);
        if ($bytes === null) {
            return null;
        }

        return [$bytes, $disk->mimeType($relative) ?: 'application/octet-stream'];
    }

    /**
     * Stream this row's file. Only image/PDF types are ever served inline,
     * with nosniff + a sandboxing CSP so an old public-era upload with a
     * hostile payload cannot execute as a page.
     *
     * @param 'signature'|'wet-ink' $kind
     */
    public function fileResponse(string $kind): \Symfony\Component\HttpFoundation\Response
    {
        $read = self::readStored($kind === 'wet-ink' ? $this->wet_ink_upload_path : $this->party_signature_path);
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
        // Images get the sandboxing CSP. A PDF must NOT: Chrome's built-in PDF
        // viewer will not render inside a CSP-sandboxed response. An
        // application/pdf body with nosniff cannot execute as a page.
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
        }

        return response($bytes, 200, $headers);
    }

    /**
     * §16 — correcting a wrong or unreadable wet-ink upload, AND (conductor
     * brief 2026-09-29) resolving an awaiting_wet_ink row the FIRST time a
     * scan actually arrives — the same transition, two different starting
     * points. The document is evidence: never edited in place, never
     * destroyed (non-negotiable #1). This marks the existing row superseded
     * and captures a fresh DISPOSITION_WET_INK row via capture() itself
     * (never duplicating its invariants), inside one transaction so no
     * window exists where either zero or two rows are live for this party.
     * Old row's own file (if it had one) is left on disk untouched — the
     * audit trail points to it via supersededBy(), not by removing it.
     *
     * [design call] Refused once the agent has already signed: the agent's
     * signature attests to the complete record as it stood (§15.2a) — a
     * wet-ink upload correction after that point would silently change what
     * was attested to. Not asked for here; if a completed inspection's
     * evidence ever needs correcting, that is a separate, larger amendment
     * mechanism, not this one.
     */
    public static function supersedeWetInk(self $existing, RentalInspection $inspection, \Illuminate\Http\UploadedFile $file, ?int $recordedByUserId): self
    {
        if (! $existing->isReplaceableWetInk()) {
            throw new \LogicException('Only a wet-ink or awaiting-wet-ink disposition can be superseded this way — a signed or refused disposition is not corrected by re-upload.');
        }
        if ($existing->superseded_at !== null) {
            throw new \LogicException('This wet-ink upload has already been superseded.');
        }
        $inspection->assertRecordable();
        if ($inspection->hasAgentSignature()) {
            throw new \LogicException('Cannot replace a wet-ink upload once the agent has signed — the agent\'s signature already attests to this record as it stood.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($existing, $inspection, $file, $recordedByUserId) {
            $existing->forceFill(['superseded_at' => now()])->save();

            $replacement = self::capture($inspection, $existing->party_role, self::DISPOSITION_WET_INK, [
                'party_contact_id' => $existing->party_contact_id,
                'wet_ink_upload_path' => self::storeWetInkUpload($file, $inspection->property_id),
                'recorded_by_user_id' => $recordedByUserId,
            ]);

            $existing->forceFill(['superseded_by_signature_id' => $replacement->id])->save();

            return $replacement;
        });
    }
}

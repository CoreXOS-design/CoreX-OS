<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §3.2/§3.6/§0.7 — the tenant's (or
 * agent-on-behalf) signature on an out-inspection. Lightweight canvas
 * capture, not the full DocuPerfect e-sign ceremony.
 */
class RentalInspectionSignature extends Model
{
    use BelongsToAgency;

    public const SIGNER_TENANT = 'tenant';
    public const SIGNER_AGENT_ON_BEHALF = 'agent_on_behalf';
    public const SIGNER_LANDLORD = 'landlord';

    /**
     * §0.7 / §11 — Johan's exact required wording when an agent signs on the
     * tenant's behalf because the signing window lapsed unsigned.
     */
    public const REQUIRED_REFUSAL_PHRASE = 'tenant refused to sign out inspection';

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'signer_role',
        'signer_contact_id',
        'signed_by_user_id',
        'signature_path',
        'refused_note',
        'signed_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function signerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'signer_contact_id');
    }

    public function signedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }

    /** §11 acceptance criteria — the exact phrase must be present, case-insensitively. */
    public static function refusalNoteIsValid(?string $note): bool
    {
        return $note !== null && str_contains(strtolower($note), strtolower(self::REQUIRED_REFUSAL_PHRASE));
    }

    /**
     * §0.7/§11 — record a signature on an inspection. An agent_on_behalf
     * signature is rejected outright unless refused_note carries Johan's
     * exact required phrase.
     */
    public static function capture(RentalInspection $inspection, string $signerRole, array $attributes = []): self
    {
        if ($signerRole === self::SIGNER_AGENT_ON_BEHALF && ! self::refusalNoteIsValid($attributes['refused_note'] ?? null)) {
            throw new \InvalidArgumentException(
                'An agent_on_behalf signature requires refused_note to contain: "' . self::REQUIRED_REFUSAL_PHRASE . '".'
            );
        }

        return self::create(array_merge($attributes, [
            'agency_id' => $inspection->agency_id,
            'rental_inspection_id' => $inspection->id,
            'signer_role' => $signerRole,
            'signed_at' => $attributes['signed_at'] ?? now(),
        ]));
    }

    /**
     * §3.6/§14.1 — decode a canvas-captured signature (base64 PNG) and store
     * it, returning the public URL to save as signature_path. Same
     * lightweight pattern already proven for compliance sign-off
     * (resources/views/compliance/policy-ack/sign.blade.php) — not a second
     * pipeline. Pulled out of the controller deliberately: the canvas is a
     * thin client, this is the one place the decode-and-store logic lives,
     * so a future mobile API controller calls this exact method instead of
     * re-implementing it — the conductor's own instruction, since signing
     * is the action Andre's app will most want to call directly.
     */
    public static function storeCanvasImage(string $base64, int $propertyId): string
    {
        $data = str_contains($base64, ',') ? explode(',', $base64, 2)[1] : $base64;
        $binary = base64_decode($data, true) ?: '';

        // No EXIF-orientation step — unlike a phone camera photo, a
        // canvas-drawn signature has no camera orientation to correct.
        $path = "properties/{$propertyId}/rental-inspection-signatures/" . uniqid('sig_', true) . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $binary);

        return \Illuminate\Support\Facades\Storage::url($path);
    }
}

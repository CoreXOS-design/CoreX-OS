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

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
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
            if ($inspection->hasAgentSignature()) {
                throw new \LogicException('The agent has already signed this inspection.');
            }
            if ($inspection->outstandingSignatories()->isNotEmpty()) {
                throw new \LogicException('Cannot record the agent\'s signature until every tenant and the landlord has a disposition recorded — the agent\'s signature attests to the complete record, not a partial one.');
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

            $alreadyDispositioned = $inspection->signatures()
                ->where('party_role', $partyRole)
                ->where('party_contact_id', $contactId)
                ->exists();
            if ($alreadyDispositioned) {
                throw new \LogicException('This party already has a disposition recorded on this inspection.');
            }

            if ($disposition === self::DISPOSITION_SIGNED) {
                if (empty($attributes['party_signature_path'])) {
                    throw new \InvalidArgumentException('A signed disposition requires party_signature_path.');
                }
                $attributes['refusal_reason_preset'] = null;
                $attributes['refusal_reason_note'] = null;
            } else { // refused
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
     * §3.6/§14.1 — decode a canvas-captured signature (base64 PNG) and store
     * it, returning the public URL to save as party_signature_path. Same
     * lightweight pattern already proven for compliance sign-off. Pulled out
     * of the controller deliberately: a future mobile API controller calls
     * this exact method instead of re-implementing it (§15.10).
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

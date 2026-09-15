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
}

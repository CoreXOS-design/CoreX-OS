<?php

declare(strict_types=1);

namespace App\Models\Compliance;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PPRA FFC renewal — Confirmation of Employment letter.
 * .ai/specs/ppra-ffc-employment-letter.md
 *
 * A fixed-order, two-signer, PIN-signed internal letter (agent signs first,
 * then the agency's resolved principal). Every merge field (name, ID
 * number, FFC reference, designation, agency legal/trading name, agency
 * PPRA firm number) is read LIVE from users/agencies/branches at render
 * time while the letter is unsigned — this model stores only what the
 * signing ceremony itself produces.
 *
 * Status machine: draft -> awaiting_agent_signature -> (agent signs, PIN)
 * -> awaiting_principal_signature -> (principal signs, PIN) -> signed.
 * A freshly created letter is persisted directly as
 * awaiting_agent_signature, never left sitting in draft — every merge
 * field is validated present BEFORE the row is created (missing-data
 * blocks happen pre-creation, see PpraEmploymentLetterService), so there is
 * nothing left to "draft" once a row exists. STATUS_DRAFT is kept in the
 * enum for forward compatibility with the status machine named in the
 * spec; no code path persists it today.
 */
class PpraEmploymentLetter extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'ppra_employment_letters';

    public const STATUS_DRAFT                      = 'draft';
    public const STATUS_AWAITING_AGENT_SIGNATURE    = 'awaiting_agent_signature';
    public const STATUS_AWAITING_PRINCIPAL_SIGNATURE = 'awaiting_principal_signature';
    public const STATUS_SIGNED                     = 'signed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_AWAITING_AGENT_SIGNATURE,
        self::STATUS_AWAITING_PRINCIPAL_SIGNATURE,
        self::STATUS_SIGNED,
    ];

    /**
     * PPRA's own published head-office address — the default addressee
     * block when an agency has not set its own
     * ppra_employment_letter_address_block. This is the regulator's
     * address, not HFC's, so it is correct for every agency out of the box
     * (CLAUDE.md Non-negotiable #9) while remaining agency-editable.
     */
    public const DEFAULT_PPRA_ADDRESS_BLOCK = "The Property Practitioners Regulatory Board\n63 Wierda Road East\nSandton\n2196";

    protected $fillable = [
        'agency_id',
        'user_id',
        'principal_user_id',
        'branch_id',
        'created_by_user_id',
        'status',
        'agent_signature_image',
        'principal_signature_image',
        'agent_signed_at',
        'agent_signed_ip',
        'principal_signed_at',
        'principal_signed_ip',
        'signed_pdf_path',
        'reminder_last_sent_at',
    ];

    protected $casts = [
        'agent_signature_image'     => 'encrypted',
        'principal_signature_image' => 'encrypted',
        'agent_signed_at'           => 'datetime',
        'principal_signed_at'       => 'datetime',
        'reminder_last_sent_at'     => 'datetime',
    ];

    /** Never leak the baked signature images through a toArray()/JSON response. */
    protected $hidden = ['agent_signature_image', 'principal_signature_image'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isAwaitingAgentSignature(): bool
    {
        return $this->status === self::STATUS_AWAITING_AGENT_SIGNATURE;
    }

    public function isAwaitingPrincipalSignature(): bool
    {
        return $this->status === self::STATUS_AWAITING_PRINCIPAL_SIGNATURE;
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    /** A letter the signing agent may still cancel/archive themselves — never once signed. */
    public function isCancellableByAgent(): bool
    {
        return ! $this->isSigned();
    }

    /**
     * OWN / BRANCH / AGENCY visibility for the Admin register — BUILD_STANDARD §1c.
     * Resolved off `ppra_employment_letters.view`'s stored scope
     * (PermissionService::getDataScope). The agency boundary itself is
     * already enforced by BelongsToAgency's global scope; this narrows
     * WITHIN that agency. 'own' here means "letters about ME" (the
     * signing agent) — a user's own queue of letters awaiting THEIR
     * principal signature is a separate, business-specific query (see
     * PpraEmploymentLetterController::index()), not this scope.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $scope = PermissionService::getDataScope($user, 'ppra_employment_letters');

        return match ($scope) {
            'own'    => $query->where('user_id', $user->id),
            'branch' => $query->where('branch_id', $user->effectiveBranchId()),
            'all'    => $query,
            default  => $query->whereRaw('1 = 0'),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT                       => 'Draft',
            self::STATUS_AWAITING_AGENT_SIGNATURE     => 'Awaiting your signature',
            self::STATUS_AWAITING_PRINCIPAL_SIGNATURE => 'Awaiting principal signature',
            self::STATUS_SIGNED                       => 'Signed',
            default                                   => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}

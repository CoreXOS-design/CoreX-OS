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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * WET-INK FLOW (spec §20, 2026-10-06): PIN signing is retired. A letter is printed, signed in wet ink outside CoreX and
 * the signed copy is uploaded: awaiting_signed_copy -> signed_copy_filed (first upload; stays filed on re-upload). The
 * scans live in ppra_employment_letter_files, reached only through files()/currentFile(). The legacy statuses
 * (draft, awaiting_agent_signature, awaiting_principal_signature, signed) stay in the enum for letters created under
 * the PIN ceremony and display sensibly — see statusLabel().
 */
class PpraEmploymentLetter extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'ppra_employment_letters';

    public const STATUS_DRAFT                      = 'draft';
    public const STATUS_AWAITING_AGENT_SIGNATURE    = 'awaiting_agent_signature';
    public const STATUS_AWAITING_PRINCIPAL_SIGNATURE = 'awaiting_principal_signature';
    /** LEGACY: the PIN-signed, system-baked letter. No new letter ever reaches it. */
    public const STATUS_SIGNED                     = 'signed';
    public const STATUS_AWAITING_SIGNED_COPY       = 'awaiting_signed_copy';
    public const STATUS_SIGNED_COPY_FILED          = 'signed_copy_filed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_AWAITING_AGENT_SIGNATURE,
        self::STATUS_AWAITING_PRINCIPAL_SIGNATURE,
        self::STATUS_SIGNED,
        self::STATUS_AWAITING_SIGNED_COPY,
        self::STATUS_SIGNED_COPY_FILED,
    ];

    /** Every status that means "still waiting for the wet-ink signed copy" — the new one plus the pre-wet-ink leftovers. */
    public const AWAITING_COPY_STATUSES = [
        self::STATUS_AWAITING_SIGNED_COPY,
        self::STATUS_DRAFT,
        self::STATUS_AWAITING_AGENT_SIGNATURE,
        self::STATUS_AWAITING_PRINCIPAL_SIGNATURE,
    ];

    /** The statuses offered in the Admin register's status filter (the legacy ones fold into these two). */
    public const FILTER_STATUSES = [
        self::STATUS_AWAITING_SIGNED_COPY,
        self::STATUS_SIGNED_COPY_FILED,
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
        'reminder_last_sent_at', // LEGACY — the reminder job is retired (spec §20)
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

    /** Every uploaded signed copy, newest first (the first is the current one). ONE record both screens read. */
    public function files(): HasMany
    {
        return $this->hasMany(PpraEmploymentLetterFile::class, 'letter_id')->orderByDesc('id');
    }

    /** The current signed copy — the most recently uploaded row. */
    public function currentFile(): HasOne
    {
        return $this->hasOne(PpraEmploymentLetterFile::class, 'letter_id')->latestOfMany();
    }

    public function isAwaitingSignedCopy(): bool
    {
        return in_array($this->status, self::AWAITING_COPY_STATUSES, true);
    }

    public function isSignedCopyFiled(): bool
    {
        return $this->status === self::STATUS_SIGNED_COPY_FILED;
    }

    /** LEGACY: signed through the retired PIN ceremony (its baked PDF is at signed_pdf_path). */
    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    /** The agent may archive their own letter until its signed copy is filed (or it was legacy PIN-signed). */
    public function isCancellableByAgent(): bool
    {
        return $this->isAwaitingSignedCopy();
    }

    /** Statuses that mean "awaiting the signed copy" (new + legacy leftovers), for the Admin status filter. */
    public function scopeWithStatusGroup(Builder $query, string $status): Builder
    {
        return $status === self::STATUS_AWAITING_SIGNED_COPY
            ? $query->whereIn('status', self::AWAITING_COPY_STATUSES)
            : $query->where('status', $status);
    }

    /**
     * THE one rule for the Admin register (sidebar link, list, New letter, show, download, archive/restore):
     * the user may manage letters for others — `ppra_employment_letters.manage`, ticked per role in Role Manager.
     * Everyone else uses My Portal → Documents for their own letter. Every admin gate calls this so the sidebar
     * link, the list and New letter can never disagree again; the own/branch/all narrowing below is separate.
     */
    public static function userCanUseAdminRegister(User $user): bool
    {
        return $user->hasPermission('ppra_employment_letters.manage');
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
        // Neutral wording — these labels show on the Admin list for someone else's letter. The pre-wet-ink statuses
        // (letters made under the retired PIN ceremony) read as "waiting for the signed copy", which is what they now are.
        return match ($status) {
            self::STATUS_AWAITING_SIGNED_COPY,
            self::STATUS_DRAFT,
            self::STATUS_AWAITING_AGENT_SIGNATURE,
            self::STATUS_AWAITING_PRINCIPAL_SIGNATURE => 'Awaiting signed copy',
            self::STATUS_SIGNED_COPY_FILED            => 'Signed copy filed',
            self::STATUS_SIGNED                       => 'Signed (electronic)',
            default                                   => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}

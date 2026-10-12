<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-432 Phase 2 — .ai/specs/auctions.md §5.4, §10. A contact's
 * registration against a specific auction. Not a role on the property —
 * see the migration's docblock for why this is its own table.
 */
class AuctionBidder extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_FICA_PENDING = 'fica_pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_FICA_PENDING,
        self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_WITHDRAWN,
    ];

    public const BIDDING_FOR_OPTIONS = ['self', 'entity', 'agent_for_third_party'];
    public const REGISTRATION_SOURCES = ['online', 'at_door', 'staff'];

    /** Matches the migration's DB-level default — see AuctionLot's identical docblock for why. */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'bidding_for' => 'self',
        'registration_source' => 'staff',
        'deposit_required' => false,
    ];

    protected $fillable = [
        'agency_id', 'auction_id', 'contact_id', 'paddle_number', 'status', 'bidding_for',
        'entity_contact_id', 'authority_document_id', 'fica_status', 'fica_verified_at', 'fica_verified_by_id',
        'deposit_required', 'deposit_amount', 'deposit_received_at', 'deposit_reference',
        'deposit_refunded_at', 'deposit_refund_reference', 'rules_signed_at', 'rules_document_id',
        'registration_source', 'max_proxy_bid', 'declined_reason', 'approved_at', 'approved_by_id',
    ];

    protected $casts = [
        'fica_verified_at' => 'datetime',
        'deposit_required' => 'boolean',
        'deposit_amount' => 'decimal:2',
        'deposit_received_at' => 'datetime',
        'deposit_refunded_at' => 'datetime',
        'rules_signed_at' => 'datetime',
        'max_proxy_bid' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function entityContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'entity_contact_id');
    }

    public function ficaVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fica_verified_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function hasDeposit(): bool
    {
        return ! $this->deposit_required || $this->deposit_received_at !== null;
    }

    public function hasSignedRules(): bool
    {
        return $this->rules_signed_at !== null;
    }

    public function isFicaVerified(): bool
    {
        return $this->fica_verified_at !== null;
    }
}

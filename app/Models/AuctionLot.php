<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-432 — .ai/specs/auctions.md §5.3 / §6.2. A property's place in an
 * auction. All status transitions go through AuctionLotStatusService —
 * never set `status` directly here or in a controller (§6.2).
 */
class AuctionLot extends Model
{
    use BelongsToAgency, SoftDeletes;

    // §6.2 — Auction lot lifecycle. Slugs match
    // PropertySettingItem::AUCTION_LOT_STATUS_SLUGS exactly — that constant
    // is the single other place these strings may ever be repeated.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CATALOGUED = 'catalogued';
    public const STATUS_OPEN_FOR_BIDS = 'open_for_bids';
    public const STATUS_UNDER_THE_HAMMER = 'under_the_hammer';
    public const STATUS_SOLD = 'sold';
    public const STATUS_SOLD_SUBJECT_TO_CONFIRMATION = 'sold_subject_to_confirmation';
    public const STATUS_PASSED_IN = 'passed_in';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Terminal — no further bidding, no further transition except by a human decision recorded elsewhere. */
    public const CONCLUDED_STATUSES = [
        self::STATUS_SOLD, self::STATUS_PASSED_IN, self::STATUS_WITHDRAWN,
    ];

    /**
     * Matches the migration's DB-level default. Eloquent does not re-fetch a
     * column's DB default after create() — without this, a freshly-created
     * lot's in-memory `status` reads as null until the caller explicitly
     * refresh()es, which is exactly the staleness bug
     * AuctionLotStatusService's own defensive refresh() calls exist to
     * guard against for service callers; this covers every other caller.
     */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'agency_id', 'auction_id', 'property_id', 'lot_number', 'reserve_price',
        'guide_price_min', 'guide_price_max', 'opening_bid', 'bid_increment',
        'buyers_premium_percent', 'sellers_commission_percent', 'deposit_percent', 'deposit_amount',
        'status', 'hammer_price', 'hammer_at', 'winning_bid_id', 'winning_bidder_id', 'reserve_met',
        'confirmation_deadline', 'confirmed_at', 'confirmed_by_id', 'deal_id', 'withdrawn_reason', 'passed_in_at',
    ];

    protected $casts = [
        'reserve_price' => 'decimal:2',
        'guide_price_min' => 'decimal:2',
        'guide_price_max' => 'decimal:2',
        'opening_bid' => 'decimal:2',
        'bid_increment' => 'decimal:2',
        'buyers_premium_percent' => 'decimal:2',
        'sellers_commission_percent' => 'decimal:2',
        'deposit_percent' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'hammer_price' => 'decimal:2',
        'hammer_at' => 'datetime',
        'reserve_met' => 'boolean',
        'confirmation_deadline' => 'datetime',
        'confirmed_at' => 'datetime',
        'passed_in_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function winningBidder(): BelongsTo
    {
        return $this->belongsTo(AuctionBidder::class, 'winning_bidder_id');
    }

    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(AuctionBid::class, 'winning_bid_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AuctionLotStatusHistory::class)->orderByDesc('created_at');
    }

    public function isConcluded(): bool
    {
        return in_array($this->status, self::CONCLUDED_STATUSES, true);
    }

    /** Current label for this lot's status, honouring an agency's rename of the default (§5.9). */
    public function statusLabel(): string
    {
        $labels = PropertySettingItem::auctionLotStatusLabelsFor((int) $this->agency_id);
        return $labels[$this->status] ?? ucwords(str_replace('_', ' ', (string) $this->status));
    }
}

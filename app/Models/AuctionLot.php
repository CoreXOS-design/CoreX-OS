<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One property offered in one auction (.ai/specs/auctions.md §4.4). */
class AuctionLot extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_OPEN = 'open';
    public const STATUS_SOLD = 'sold';
    public const STATUS_SOLD_SUBJECT = 'sold_subject_to_confirmation';
    public const STATUS_PASSED_IN = 'passed_in';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_OPEN, self::STATUS_SOLD,
        self::STATUS_SOLD_SUBJECT, self::STATUS_PASSED_IN, self::STATUS_WITHDRAWN,
    ];

    /** Finished for good: the property is free to go into another auction. */
    public const CONCLUDED_STATUSES = [self::STATUS_SOLD, self::STATUS_PASSED_IN, self::STATUS_WITHDRAWN];

    protected $fillable = [
        'agency_id', 'auction_id', 'property_id', 'lot_number', 'title', 'reference', 'external_ref', 'description',
        'opens_at', 'closes_at', 'opening_bid', 'reserve_price', 'guide_price_min', 'guide_price_max', 'status',
        'winning_bid_id', 'hammer_price', 'result_at', 'confirmation_deadline', 'result_note',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'result_at' => 'datetime',
        'confirmation_deadline' => 'datetime',
        'lot_number' => 'integer',
        'opening_bid' => 'decimal:2',
        'reserve_price' => 'decimal:2',
        'guide_price_min' => 'decimal:2',
        'guide_price_max' => 'decimal:2',
        'hammer_price' => 'decimal:2',
    ];

    public function auction()
    {
        return $this->belongsTo(Auction::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function bids()
    {
        // A lot's bids always belong to the lot's own agency; never filter them by whoever happens to be browsing.
        return $this->hasMany(AuctionBid::class, 'lot_id')->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class);
    }

    /** True once any valid bid exists: the window, opening bid and reserve are then locked. */
    public function hasBids(): bool
    {
        return $this->bids()->valid()->exists();
    }

    /** The current highest valid bid (the earlier one wins an exact tie). */
    public function highestBid(): ?AuctionBid
    {
        return $this->bids()->valid()->orderByDesc('amount')->orderBy('id')->first();
    }

    public function isConcluded(): bool
    {
        return in_array($this->status, self::CONCLUDED_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', (string) $this->status));
    }

    public function displayTitle(): string
    {
        return (string) ($this->title ?: ($this->property?->title ?: 'Lot ' . $this->lot_number));
    }

    /** Bidding window: the lot's own dates, falling back to the auction's. */
    public function windowOpensAt()
    {
        return $this->opens_at ?? $this->auction?->starts_at;
    }

    public function windowClosesAt()
    {
        return $this->closes_at ?? $this->auction?->ends_at;
    }

    /**
     * When the sale happens, for adverts and portal feeds: a live auction sells when the auction starts, a timed one when
     * bidding on the lot closes. Null unless the lot is published or open (a draft, finished or withdrawn lot is not advertised).
     */
    public function advertisedDate()
    {
        if (! in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_OPEN], true) || ! $this->auction) {
            return null;
        }

        return $this->auction->format === 'live' ? $this->auction->starts_at : $this->windowClosesAt();
    }

    /** Venue line for adverts: "Name, address", else "Online auction" for a timed auction, else ''. */
    public function advertisedVenue(): string
    {
        $name = trim((string) $this->auction?->venue_name);
        $address = trim((string) $this->auction?->venue_address);
        $venue = $name !== '' && $address !== '' ? $name . ', ' . $address : ($name !== '' ? $name : $address);

        return $venue === '' && $this->auction?->format === 'timed' ? 'Online auction' : $venue;
    }

    /** Lots of properties the user may see: AGENCY all; BRANCH the branch's properties; OWN the user's listings (spec §5). */
    public function scopeVisibleTo($query, User $user)
    {
        $scope = PermissionService::getDataScope($user, 'auctions');

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->whereHas('property', fn ($p) => $p->where('branch_id', $user->effectiveBranchId()));
        }
        if ($scope === 'own') {
            return $query->whereHas('property', fn ($p) => $p->whereIn('agent_id', $user->dataIdentityIds()));
        }

        return $query->whereRaw('1 = 0');
    }

    /** Lots that still hold their property (not yet sold, passed in or withdrawn). */
    public function scopeLive($query)
    {
        return $query->whereNotIn('status', self::CONCLUDED_STATUSES);
    }

    /** Next free lot number in an auction; counts archived lots so a number is never reused. */
    public static function nextLotNumber(int $auctionId): int
    {
        return (int) static::withTrashed()->withoutGlobalScopes()->where('auction_id', $auctionId)->max('lot_number') + 1;
    }
}

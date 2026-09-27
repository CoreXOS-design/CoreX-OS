<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-432 — .ai/specs/auctions.md §5.2. The sale event. One auction may
 * carry many lots; a single-property auction is an auction with one lot.
 */
class Auction extends Model
{
    use BelongsToAgency, SoftDeletes;

    // §6.1 — Auction lifecycle.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_REGISTRATION_OPEN = 'registration_open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_POSTPONED = 'postponed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_REGISTRATION_OPEN,
        self::STATUS_IN_PROGRESS, self::STATUS_CLOSED, self::STATUS_SETTLED,
        self::STATUS_POSTPONED, self::STATUS_CANCELLED,
    ];

    public const AUCTIONEER_KINDS = ['internal', 'external'];
    public const BIDDING_MODES = ['in_room', 'online', 'hybrid'];

    /** Matches the migration's DB-level default — see AuctionLot's identical docblock for why. */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'agency_id', 'branch_id', 'reference', 'title', 'auction_type_id', 'bidding_mode',
        'auctioneer_kind', 'auctioneer_user_id', 'auctioneer_contact_id', 'auctioneer_company',
        'auctioneer_licence_no', 'starts_at', 'ends_at', 'registration_opens_at', 'registration_closes_at',
        'venue_name', 'venue_address', 'venue_lat', 'venue_lng', 'is_online_streamed', 'stream_url',
        'status', 'rules_document_id', 'conditions_document_id', 'catalogue_published_at', 'notes',
        'created_by_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_opens_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'venue_lat' => 'decimal:7',
        'venue_lng' => 'decimal:7',
        'is_online_streamed' => 'boolean',
        'catalogue_published_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function auctionType(): BelongsTo
    {
        return $this->belongsTo(PropertySettingItem::class, 'auction_type_id');
    }

    public function auctioneerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auctioneer_user_id');
    }

    public function auctioneerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'auctioneer_contact_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(AuctionLot::class)->orderBy('lot_number');
    }

    public function isInternal(): bool
    {
        return $this->auctioneer_kind === 'internal';
    }

    /** §9 step 5 — an auction is public once its catalogue has gone live. */
    public function isCataloguePublished(): bool
    {
        return $this->catalogue_published_at !== null;
    }

    /** §10.1 — whether a new bidder may still register right now. */
    public function isRegistrationOpen(): bool
    {
        if (in_array($this->status, [self::STATUS_CLOSED, self::STATUS_SETTLED, self::STATUS_CANCELLED], true)) {
            return false;
        }
        if ($this->registration_opens_at && now()->lessThan($this->registration_opens_at)) {
            return false;
        }
        if ($this->registration_closes_at && now()->greaterThan($this->registration_closes_at)) {
            return false;
        }

        return true;
    }
}

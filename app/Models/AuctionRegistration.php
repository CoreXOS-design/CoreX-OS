<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A buyer registered to bid in ONE auction (.ai/specs/auctions.md §4.5). Only an approved registration may bid. */
class AuctionRegistration extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DECLINED, self::STATUS_WITHDRAWN];

    public const TYPES = ['individual' => 'Individual', 'company' => 'Company'];

    protected $fillable = [
        'agency_id', 'auction_id', 'client_user_id', 'contact_id', 'bidder_type', 'company_name', 'company_reg_no', 'bidder_number',
        'status', 'declined_reason', 'approved_by', 'approved_at', 'accepted_conditions_at', 'accepted_conditions_ip',
    ];

    protected $casts = ['approved_at' => 'datetime', 'accepted_conditions_at' => 'datetime', 'bidder_number' => 'integer'];

    public function auction()
    {
        return $this->belongsTo(Auction::class)->withTrashed();
    }

    public function clientUser()
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class)->withoutGlobalScopes();
    }

    public function documents()
    {
        return $this->hasMany(AuctionRegistrationDocument::class, 'registration_id');
    }

    public function bids()
    {
        return $this->hasMany(AuctionBid::class, 'registration_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED && ! $this->trashed();
    }

    public function statusLabel(): string
    {
        return ucfirst((string) $this->status);
    }

    /** Who the bidder is, for staff: the company, else the contact's name, else the login email. */
    public function displayName(): string
    {
        if ($this->bidder_type === 'company' && trim((string) $this->company_name) !== '') {
            return (string) $this->company_name;
        }
        $name = trim(($this->contact?->first_name ?? '') . ' ' . ($this->contact?->last_name ?? ''));

        return $name !== '' ? $name : (string) ($this->clientUser?->email ?? 'Bidder');
    }

    /**
     * OWN / BRANCH / AGENCY (spec §5): `auctions.bidders.view_all` sees every registration in the agency; otherwise the
     * registrations of auctions the user's auctions data scope can see.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasPermission('auctions.bidders.view_all')) {
            return $query;
        }

        return $query->whereIn('auction_id', Auction::visibleTo($user)->withTrashed()->select('auctions.id'));
    }
}

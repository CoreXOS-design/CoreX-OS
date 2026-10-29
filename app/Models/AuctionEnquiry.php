<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An enquiry sent from the public auction site (.ai/specs/auctions.md §4.8, screen S3). */
class AuctionEnquiry extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_CLOSED = 'closed';
    public const STATUSES = [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_CLOSED];

    protected $fillable = [
        'agency_id', 'auction_id', 'lot_id', 'property_id', 'client_user_id', 'contact_id', 'name', 'email', 'phone',
        'message', 'status', 'assigned_user_id', 'source', 'consent_popia_at', 'contacted_at',
    ];

    protected $casts = ['consent_popia_at' => 'datetime', 'contacted_at' => 'datetime'];

    public function auction()
    {
        return $this->belongsTo(Auction::class)->withTrashed();
    }

    public function lot()
    {
        return $this->belongsTo(AuctionLot::class, 'lot_id')->withTrashed();
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function statusLabel(): string
    {
        return ucfirst((string) $this->status);
    }

    /**
     * OWN / BRANCH / AGENCY (spec §5). `auctions.enquiries.view_all` is the AGENCY tier; otherwise the data scope of the
     * auctions module applies: BRANCH sees enquiries on its branch's properties, OWN those assigned to the user or on
     * properties they list.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasPermission('auctions.enquiries.view_all')) {
            return $query;
        }

        $scope = PermissionService::getDataScope($user, 'auctions');
        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();

            return $query->where(fn ($q) => $q->whereHas('property', fn ($p) => $p->where('branch_id', $branchId))
                ->orWhereIn('assigned_user_id', $user->dataIdentityIds()));
        }
        if ($scope === 'own') {
            $ids = $user->dataIdentityIds();

            return $query->where(fn ($q) => $q->whereIn('assigned_user_id', $ids)
                ->orWhereHas('property', fn ($p) => $p->whereIn('agent_id', $ids)));
        }

        return $query->whereRaw('1 = 0');
    }
}

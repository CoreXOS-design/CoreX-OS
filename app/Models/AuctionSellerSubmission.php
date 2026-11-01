<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A "Sell at auction" questionnaire from the public auction site (.ai/specs/auctions.md §22). */
class AuctionSellerSubmission extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_CLOSED = 'closed';
    public const STATUSES = [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_CLOSED];

    public const SELLER_TYPES = ['individual' => 'An individual owner', 'married_in_community' => 'A spouse married in community of property', 'company_or_trust' => 'A company, close corporation or trust', 'deceased_estate' => 'The executor of a deceased estate'];
    public const PROPERTY_TYPES = ['house' => 'House', 'townhouse' => 'Townhouse', 'apartment' => 'Apartment', 'vacant_land' => 'Vacant land', 'farm' => 'Farm or smallholding', 'commercial' => 'Commercial', 'industrial' => 'Industrial'];
    public const FORMATS = ['online' => 'Online', 'live' => 'Live on site', 'hybrid' => 'Hybrid', 'sealed_tender' => 'Sealed tender'];
    public const CONTACT_METHODS = ['phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email'];

    protected $fillable = [
        'agency_id', 'branch_id', 'status', 'seller_type', 'name', 'email', 'phone', 'preferred_contact', 'property_type', 'address', 'suburb',
        'price_expectation', 'auction_format', 'marketing_opt_in', 'ip', 'answers', 'contact_id', 'tracked_property_id', 'assigned_user_id', 'contacted_at',
    ];

    protected $casts = ['answers' => 'array', 'marketing_opt_in' => 'boolean', 'price_expectation' => 'integer', 'contacted_at' => 'datetime'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function trackedProperty()
    {
        return $this->belongsTo(\App\Models\Prospecting\TrackedProperty::class, 'tracked_property_id');
    }

    public function statusLabel(): string
    {
        return ucfirst((string) $this->status);
    }

    /**
     * AGENCY tier (`auctions.sellers.view_all`, or an agency-wide data scope) sees every lead of the agency, including unassigned ones.
     * BRANCH sees the leads assigned to anyone in their branch; OWN the leads assigned to them. Unassigned leads are the agency tier's only.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasPermission('auctions.sellers.view_all')) {
            return $query;
        }

        $scope = PermissionService::getDataScope($user, 'auctions');
        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();

            return $query->where(fn ($q) => $q->whereIn('assigned_user_id', User::withoutGlobalScopes()->where('branch_id', $branchId)->select('id'))
                ->orWhereIn('assigned_user_id', $user->dataIdentityIds()));
        }
        if ($scope === 'own') {
            return $query->whereIn('assigned_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }
}

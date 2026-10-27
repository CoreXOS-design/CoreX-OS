<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * AT-454 — one property on one agent's Road to Sold list for one day.
 *
 * The page reads this table; only RoadToSold\ListBuilderService writes it (building, add-to-day,
 * closing the day) and, later, the outcome capture (marking an item worked).
 *
 * Scoping: BelongsToAgency (agency is the outer wall, enforced on every query) plus
 * scopeVisibleTo() for own / branch / all within the agency. A direct URL by id must go through it.
 */
class RtsListItem extends Model
{
    use BelongsToAgency, BelongsToBranch, InheritsBranchFromParent, SoftDeletes;

    protected $table = 'rts_list_items';

    public const TRACK_SALES   = 'sales';
    public const TRACK_RENTALS = 'rentals';
    public const TRACKS        = [self::TRACK_SALES, self::TRACK_RENTALS];

    public const ORIGIN_AUTO      = 'auto';
    public const ORIGIN_FOLLOW_UP = 'follow_up';
    public const ORIGIN_ADDED     = 'added';
    public const ORIGIN_ROLLOVER  = 'rollover';
    public const ORIGINS = [self::ORIGIN_AUTO, self::ORIGIN_FOLLOW_UP, self::ORIGIN_ADDED, self::ORIGIN_ROLLOVER];

    public const STATUS_OPEN   = 'open';
    public const STATUS_WORKED = 'worked';
    public const STATUS_MISSED = 'missed';
    public const STATUS_ROLLED = 'rolled';
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_WORKED, self::STATUS_MISSED, self::STATUS_ROLLED];

    /** The pseudo-selection key stored on a follow-up row. */
    public const SELECTION_FOLLOW_UP = 'follow_up';

    protected $fillable = [
        'agency_id', 'branch_id', 'user_id', 'list_date', 'track',
        'tracked_property_id', 'prospecting_listing_id', 'property_id',
        'selection', 'origin', 'position', 'status', 'reason',
        'rolled_from_item_id', 'status_changed_at', 'prospect_id', 'reason_facts',
    ];

    protected $casts = [
        'list_date'              => 'date',
        'position'               => 'integer',
        'status_changed_at'      => 'datetime',
        'tracked_property_id'    => 'integer',
        'prospecting_listing_id' => 'integer',
        'property_id'            => 'integer',
        'prospect_id'            => 'integer',
        'reason_facts'           => 'array',
    ];

    /** The branch follows the agent whose list this is. */
    protected function branchParent(): array
    {
        return [User::class, 'user_id'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    public function prospectingListing(): BelongsTo
    {
        return $this->belongsTo(ProspectingListing::class, 'prospecting_listing_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /** One agent's list for one day. */
    public function scopeForDay($query, int|User $user, $date, string $track = self::TRACK_SALES)
    {
        // a User passed in is turned into the LIST OWNER: an assistant works the list of the agent they act for (spec: road-to-sold.md "Assistants")
        $userId = $user instanceof User ? (int) ($user->is_assistant ? $user->ownershipUserId() : $user->id) : $user;

        // A range on the date column (not whereDate()): a function on the column would not use the day index.
        $day = Carbon::parse($date)->startOfDay();

        return $query->where('rts_list_items.user_id', $userId)
            ->where('rts_list_items.track', $track)
            ->where('rts_list_items.list_date', '>=', $day->toDateString())
            ->where('rts_list_items.list_date', '<', $day->copy()->addDay()->toDateString());
    }

    public function scopeOpen($query)
    {
        return $query->where('rts_list_items.status', self::STATUS_OPEN);
    }

    /** Items that count toward the day's target (everything except "added", which is reported apart). */
    /** The day's NEW intake: everything except the agent's own extras and follow-ups (road-to-sold.md 22.3: follow-ups are counted apart). */
    public function scopeCountingTowardTarget($query)
    {
        return $query->whereNotIn('rts_list_items.origin', [self::ORIGIN_ADDED, self::ORIGIN_FOLLOW_UP]);
    }

    /**
     * Own / branch / agency breadth, from the viewer's Road to Sold data scope (set in Role Manager).
     * `own` = the viewer's identity ids (an assistant's own is their agent's); `branch` = the viewer's
     * effective branch; `all` = the whole agency; anything else (null / 'none') = nothing.
     * Agency isolation is already applied by BelongsToAgency.
     */
    public function scopeVisibleTo($query, User $user, ?string $scope = null)
    {
        $scope ??= PermissionService::getDataScope($user, 'road_to_sold');

        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->where('rts_list_items.branch_id', $user->effectiveBranchId())
                : $query->whereIn('rts_list_items.user_id', $user->dataIdentityIds()),
            'own'    => $query->whereIn('rts_list_items.user_id', $user->dataIdentityIds()),
            default  => $query->whereRaw('1 = 0'),
        };
    }
}

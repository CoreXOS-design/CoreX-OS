<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An auction event that holds many lots (.ai/specs/auctions.md §4.1). A one-property
 * auction is simply an auction with one lot (is_single_property).
 */
class Auction extends Model
{
    use BelongsToAgency, BelongsToBranch, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_OPEN,
        self::STATUS_CLOSED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    public const FORMATS = ['timed' => 'Timed online', 'live' => 'Live'];

    public const TYPES = ['real_estate' => 'Real estate'];

    protected $fillable = [
        'agency_id', 'branch_id', 'reference', 'title', 'description', 'cover_image_path', 'format', 'type', 'currency',
        'status', 'starts_at', 'ends_at', 'registration_closes_at', 'venue_name', 'venue_address', 'lat', 'lng',
        'stream_url', 'soft_close_minutes', 'buyers_commission_percent', 'deposit_percent', 'is_single_property', 'published_at', 'cancelled_at', 'cancel_reason',
        'completed_at', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'published_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_single_property' => 'boolean',
        'soft_close_minutes' => 'integer',
        'buyers_commission_percent' => 'float',
        'deposit_percent' => 'float',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lots()
    {
        return $this->hasMany(AuctionLot::class)->orderBy('lot_number');
    }

    public function increments()
    {
        return $this->hasMany(AuctionIncrement::class)->orderBy('from_amount');
    }

    public function documents()
    {
        return $this->hasMany(AuctionDocument::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Visible on the public auction site (§8.1): drafts and cancelled auctions are never public. */
    public function isPublic(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_COMPLETED], true);
    }

    /** Details can still be changed; a finished or cancelled auction is a record. */
    public function isEditable(): bool
    {
        return ! in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', (string) $this->status));
    }

    /**
     * OWN / BRANCH / AGENCY (spec §5): AGENCY sees every auction; BRANCH the auctions of their branch or holding a
     * property of their branch; OWN the auctions they created or that hold a property they list. The agency boundary
     * itself is the global AgencyScope.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $scope = PermissionService::getDataScope($user, 'auctions');

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();

            return $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhereHas('lots.property', fn ($p) => $p->where('branch_id', $branchId));
            });
        }
        if ($scope === 'own') {
            $ids = $user->dataIdentityIds();

            return $query->where(function ($q) use ($ids) {
                $q->whereIn('created_by', $ids)
                    ->orWhereHas('lots.property', fn ($p) => $p->whereIn('agent_id', $ids));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /** Next free reference for the agency, e.g. AUC-2026-0007. Counts archived rows too so a reference is never reused. */
    public static function nextReference(int $agencyId): string
    {
        $prefix = AgencyAuctionSettings::referencePrefixFor($agencyId);
        $year = now()->year;
        $n = static::withTrashed()->withoutGlobalScopes()->where('agency_id', $agencyId)->whereYear('created_at', $year)->count() + 1;

        do {
            $ref = sprintf('%s-%d-%04d', $prefix, $year, $n++);
        } while (static::withTrashed()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('reference', $ref)->exists());

        return $ref;
    }

    /** The increment that applies at a given price: the highest rung whose from_amount is <= the amount. */
    public function incrementFor(float $amount): float
    {
        $rung = $this->increments->sortByDesc('from_amount')->first(fn ($i) => (float) $i->from_amount <= $amount);

        return (float) ($rung?->increment ?? 0);
    }

    /** Replace the bid ladder (rows are archived, never hard-deleted). @param array<int, array{0: mixed, 1: mixed}> $rungs */
    public function syncIncrements(array $rungs): void
    {
        $this->increments()->get()->each->delete();
        foreach ($rungs as [$from, $inc]) {
            AuctionIncrement::create([
                'agency_id' => $this->agency_id,
                'auction_id' => $this->id,
                'from_amount' => (float) $from,
                'increment' => (float) $inc,
            ]);
        }
        $this->unsetRelation('increments');
    }
}

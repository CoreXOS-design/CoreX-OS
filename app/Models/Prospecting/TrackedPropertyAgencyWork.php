<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One agency's own work record for one tracked property (property-identity.md section 12): prospecting status + reason, the own-stock flag,
 * the current claim at a glance. Scoped by agency at the query layer; written only through AgencyWorkService.
 */
final class TrackedPropertyAgencyWork extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'tracked_property_agency_work';

    public const STATUS_INTERESTED = 'interested';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_NOT_SELLING = 'not_selling';
    public const STATUS_MANDATE_ELSEWHERE = 'mandate_elsewhere';
    public const STATUS_DO_NOT_CONTACT = 'do_not_contact';

    /** Plain words for a first-time user; the keys are what is stored. */
    public const STATUSES = [
        self::STATUS_INTERESTED => 'Owner interested in selling',
        self::STATUS_CONTACTED => 'Owner contacted',
        self::STATUS_NOT_SELLING => 'Owner not selling',
        self::STATUS_MANDATE_ELSEWHERE => 'Mandate with another agency',
        self::STATUS_DO_NOT_CONTACT => 'Do not contact',
    ];

    protected $fillable = [
        'agency_id', 'tracked_property_id', 'prospecting_status', 'prospecting_reason', 'status_set_by_user_id', 'status_set_at',
        'is_own_stock', 'own_stock_property_id', 'own_stock_source', 'own_stock_synced_at', 'claimed_by_user_id', 'claimed_at',
    ];

    protected $casts = [
        'status_set_at' => 'datetime',
        'is_own_stock' => 'boolean',
        'own_stock_synced_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id')->withoutGlobalScopes();
    }

    public function statusSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_set_by_user_id')->withoutGlobalScopes();
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id')->withoutGlobalScopes();
    }

    public function statusLabel(): ?string
    {
        return $this->prospecting_status ? (self::STATUSES[$this->prospecting_status] ?? $this->prospecting_status) : null;
    }
}

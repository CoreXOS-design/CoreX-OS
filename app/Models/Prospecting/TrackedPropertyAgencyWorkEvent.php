<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of one agency's status / own-stock / claim changes on a tracked property. Never edited, never deleted. */
final class TrackedPropertyAgencyWorkEvent extends Model
{
    use BelongsToAgency;

    protected $table = 'tracked_property_agency_work_events';

    public const KIND_STATUS = 'status';
    public const KIND_OWN_STOCK = 'own_stock';
    public const KIND_CLAIM = 'claim';

    protected $fillable = ['agency_id', 'tracked_property_id', 'kind', 'from_value', 'to_value', 'reason', 'user_id', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }
}

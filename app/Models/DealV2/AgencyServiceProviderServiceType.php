<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-319 — one type a supplier provides. A supplier (AgencyServiceProvider) has
 * 1..n of these; `service_type` is the agency-configurable AgencyServiceType CODE
 * (stable value), so a label rename never rewrites the link. Soft-deleted so
 * un-ticking a type on the directory preserves the row (restore-or-create on re-add).
 */
class AgencyServiceProviderServiceType extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'service_provider_id',
        'service_type',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'service_provider_id');
    }
}

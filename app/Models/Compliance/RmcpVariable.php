<?php

namespace App\Models\Compliance;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class RmcpVariable extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'variable_key',
        'value',
        'data_source',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Get all manual variables for an agency, keyed by variable_key.
     */
    public static function forAgency(int $agencyId): \Illuminate\Support\Collection
    {
        return static::where('agency_id', $agencyId)
            ->pluck('value', 'variable_key');
    }
}

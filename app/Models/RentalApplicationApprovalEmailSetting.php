<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-392 — how many matched properties appear in the agent's approval
 * email. Follows RentalApplicationQualifyingSetting's own safe pattern:
 * forAgency() never creates a row on read, only returns a sensible
 * in-memory default until the agency explicitly saves.
 */
class RentalApplicationApprovalEmailSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_MAX_PROPERTIES_IN_EMAIL = 5;

    protected $fillable = ['agency_id', 'max_properties_in_email'];

    protected $casts = [
        'max_properties_in_email' => 'integer',
    ];

    public static function maxPropertiesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_MAX_PROPERTIES_IN_EMAIL;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row ? (int) $row->max_properties_in_email : self::DEFAULT_MAX_PROPERTIES_IN_EMAIL;
    }
}

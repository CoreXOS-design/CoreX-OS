<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyContactSettings extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_contact_settings';

    protected $fillable = [
        'agency_id',
        'sharing_mode',
        'duplicate_mode',
        'duplicate_match_fields',
        'buyer_warm_days',
        'buyer_cold_days',
        'buyer_lost_days',
        'contact_retention_years',
        'consent_retention_years',
        'access_log_retention_years',
    ];

    protected $casts = [
        'duplicate_match_fields' => 'array',
        'buyer_warm_days' => 'integer',
        'buyer_cold_days' => 'integer',
        'buyer_lost_days' => 'integer',
        'contact_retention_years' => 'integer',
        'consent_retention_years' => 'integer',
        'access_log_retention_years' => 'integer',
    ];

    /**
     * Get settings for an agency, creating defaults if none exist.
     */
    public static function forAgency(int $agencyId): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId],
            [
                'sharing_mode' => 'branch',
                'duplicate_mode' => 'soft_warn',
                'duplicate_match_fields' => ['phone', 'email', 'id_number'],
                'buyer_warm_days' => 14,
                'buyer_cold_days' => 30,
                'buyer_lost_days' => 60,
                'contact_retention_years' => 5,
                'consent_retention_years' => 5,
                'access_log_retention_years' => 5,
            ]
        );
    }
}

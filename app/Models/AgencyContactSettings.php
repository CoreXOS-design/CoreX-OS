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
        'sharing_mode', // DEPRECATED — visibility now governed by role_permissions.scope
        'buyer_pipeline_default_scope',
        'duplicate_mode',
        'duplicate_match_fields',
        'address_match_mode', // AT-60 — address-duplicate-guard aggressiveness (off|standard|strict)
        'buyer_warm_days',
        'buyer_cold_days',
        'buyer_lost_days',
        // AT-71 — agency-configurable minimum criteria for a "countable" buyer
        // wishlist. JSON array of required criteria groups; ['any'] = default.
        'min_countable_criteria',
        'contact_retention_years',
        'consent_retention_years',
        'access_log_retention_years',
    ];

    protected $casts = [
        'duplicate_match_fields' => 'array',
        'buyer_warm_days' => 'integer',
        'buyer_cold_days' => 'integer',
        'buyer_lost_days' => 'integer',
        'min_countable_criteria' => 'array',
        'contact_retention_years' => 'integer',
        'consent_retention_years' => 'integer',
        'access_log_retention_years' => 'integer',
    ];

    /**
     * AT-71 — default countable-buyer bar. ['any'] = a wishlist is countable if
     * it has AT LEAST ONE non-empty criteria field (only a completely empty
     * wishlist is uncountable). An agency may override to require specific
     * groups, e.g. ['area','price_band']. Group keys are defined by
     * ContactMatch::presentCriteriaGroups().
     */
    public const DEFAULT_MIN_COUNTABLE_CRITERIA = ['any'];

    /** Per-request cache of the resolved min-countable bar, keyed by agency id. */
    protected static array $minCountableCache = [];

    /**
     * Get settings for an agency, creating defaults if none exist.
     */
    public static function forAgency(int $agencyId): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId],
            [
                'sharing_mode' => 'branch',
                'buyer_pipeline_default_scope' => 'own',
                'duplicate_mode' => 'soft_warn',
                'duplicate_match_fields' => ['phone', 'email', 'id_number'],
                'address_match_mode' => 'standard',
                'buyer_warm_days' => 14,
                'buyer_cold_days' => 30,
                'buyer_lost_days' => 60,
                'min_countable_criteria' => self::DEFAULT_MIN_COUNTABLE_CRITERIA,
                'contact_retention_years' => 5,
                'consent_retention_years' => 5,
                'access_log_retention_years' => 5,
            ]
        );
    }

    /**
     * The resolved required-criteria-group list for this agency (NULL column →
     * code default). Always a non-empty array.
     *
     * @return string[]
     */
    public function minCountableCriteria(): array
    {
        $val = $this->min_countable_criteria;
        return (is_array($val) && !empty($val)) ? $val : self::DEFAULT_MIN_COUNTABLE_CRITERIA;
    }

    /**
     * Cached agency lookup of the countable bar. One DB resolve per agency per
     * request — safe to call inside per-match scoring loops.
     *
     * @return string[]
     */
    public static function minCountableFor(int $agencyId): array
    {
        return self::$minCountableCache[$agencyId]
            ??= self::forAgency($agencyId)->minCountableCriteria();
    }

    /** Clear the per-request min-countable cache (used after a settings change / in tests). */
    public static function clearMinCountableCache(): void
    {
        self::$minCountableCache = [];
    }
}

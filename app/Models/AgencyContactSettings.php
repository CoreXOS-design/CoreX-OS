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
        'warn_on_held_address_capture', // Part 3 — warn on capturing an address HFC already holds (default ON)
        'portal_lead_auto_seed_buyer', // Buyer loop — auto-seed criteria-bearing buyer from a portal/listing lead (default ON)
        'buyer_warm_days',
        'buyer_cold_days',
        'buyer_lost_days',
        // AT-81 — days a contact may sit PENDING (consent-request sent, no reply)
        // before being lapsed to a no_response opt-out.
        'outreach_no_response_days',
        // AT-71 — agency-configurable minimum criteria for a "countable" buyer
        // wishlist. JSON array of required criteria groups; ['any'] = default.
        'min_countable_criteria',
        // AT-75 — MIC buyer-match knobs (agency-configurable, never hardcoded).
        'mic_match_threshold',
        'mic_price_band_pct',
        'contact_retention_years',
        'consent_retention_years',
        'access_log_retention_years',
    ];

    protected $casts = [
        'duplicate_match_fields' => 'array',
        'warn_on_held_address_capture' => 'boolean',
        'portal_lead_auto_seed_buyer' => 'boolean',
        'buyer_warm_days' => 'integer',
        'buyer_cold_days' => 'integer',
        'buyer_lost_days' => 'integer',
        'outreach_no_response_days' => 'integer',
        'min_countable_criteria' => 'array',
        'mic_match_threshold' => 'integer',
        'mic_price_band_pct' => 'integer',
        'contact_retention_years' => 'integer',
        'consent_retention_years' => 'integer',
        'access_log_retention_years' => 'integer',
    ];

    /** AT-81 — default no-response window (days) before a pending contact lapses. */
    public const DEFAULT_OUTREACH_NO_RESPONSE_DAYS = 7;

    /** AT-75 — MIC tile/slider threshold floor (%) when the column is null. */
    public const DEFAULT_MIC_MATCH_THRESHOLD = 75;
    /** AT-75 — price-band drift tolerance (%) past the stated band before decay. */
    public const DEFAULT_MIC_PRICE_BAND_PCT = 10;

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
                'warn_on_held_address_capture' => true,
                'portal_lead_auto_seed_buyer' => true,
                'buyer_warm_days' => 14,
                'buyer_cold_days' => 30,
                'buyer_lost_days' => 60,
                'outreach_no_response_days' => self::DEFAULT_OUTREACH_NO_RESPONSE_DAYS,
                'min_countable_criteria' => self::DEFAULT_MIN_COUNTABLE_CRITERIA,
                'mic_match_threshold' => self::DEFAULT_MIC_MATCH_THRESHOLD,
                'mic_price_band_pct' => self::DEFAULT_MIC_PRICE_BAND_PCT,
                'contact_retention_years' => 5,
                'consent_retention_years' => 5,
                'access_log_retention_years' => 5,
            ]
        );
    }

    /** AT-81 — resolved no-response window (days), null-safe, clamped to ≥1. */
    public function outreachNoResponseDays(): int
    {
        $v = (int) ($this->outreach_no_response_days ?? self::DEFAULT_OUTREACH_NO_RESPONSE_DAYS);
        return max(1, $v);
    }

    /** AT-75 — resolved MIC match threshold (%), clamped 1-100. */
    public function micMatchThreshold(): int
    {
        $v = (int) ($this->mic_match_threshold ?? self::DEFAULT_MIC_MATCH_THRESHOLD);
        return max(1, min(100, $v));
    }

    /** AT-75 — resolved MIC price-band drift tolerance as a fraction (e.g. 0.10). */
    public function micPriceBandFraction(): float
    {
        $pct = (int) ($this->mic_price_band_pct ?? self::DEFAULT_MIC_PRICE_BAND_PCT);
        return max(0, min(100, $pct)) / 100;
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

    /**
     * Part 3 — is the "already on our books" capture warning enabled for this agency?
     * Null-safe; defaults ON. (A separately-disabled address_match_mode='off' also
     * suppresses the warning at the guard level, since no matching runs.)
     */
    public function warnsOnHeldAddressCapture(): bool
    {
        return (bool) ($this->warn_on_held_address_capture ?? true);
    }

    /**
     * Buyer loop — does a portal/listing lead auto-seed a criteria-bearing buyer
     * (derived wishlist → pipeline landing → MIC demand)? Null-safe; defaults ON.
     */
    public function portalLeadAutoSeedBuyer(): bool
    {
        return (bool) ($this->portal_lead_auto_seed_buyer ?? true);
    }

    /** Clear the per-request min-countable cache (used after a settings change / in tests). */
    public static function clearMinCountableCache(): void
    {
        self::$minCountableCache = [];
    }
}

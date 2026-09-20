<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/rental-inspections.md §3.5 — the two windows, agency-
 * configurable, never hardcoded (§0.12). Read-time default pattern, matching
 * RentalApplicationQualifyingSetting: a null column resolves to the
 * DEFAULT_* constant, never written on read.
 */
class RentalInspectionSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_FAULT_REPORT_WINDOW_DAYS = 7;
    public const DEFAULT_SIGNING_WINDOW_DAYS = 7;

    /**
     * §15.6 — neutral, multi-agency-safe default. 'other' is always present
     * and always last, never agency-removable (§15.5's mandatory-reason
     * guarantee depends on an escape valve existing) — enforced in
     * refusalReasonPresetsFor() below, not left to agency-edited JSON to
     * get right.
     */
    public const DEFAULT_REFUSAL_REASON_PRESETS = [
        ['key' => 'disputes_condition', 'label' => 'Disputes the recorded condition'],
        ['key' => 'not_present', 'label' => 'Not present for the walkthrough'],
        ['key' => 'refused_no_reason', 'label' => 'Refused outright, no reason given'],
        ['key' => 'other', 'label' => 'Other'],
    ];

    protected $fillable = [
        'agency_id',
        'fault_report_window_days',
        'out_inspection_signing_window_days',
        'refusal_reason_presets',
    ];

    protected $casts = [
        'fault_report_window_days' => 'integer',
        'out_inspection_signing_window_days' => 'integer',
        'refusal_reason_presets' => 'array',
    ];

    public static function faultReportWindowDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_FAULT_REPORT_WINDOW_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('fault_report_window_days');

        return $value !== null ? (int) $value : self::DEFAULT_FAULT_REPORT_WINDOW_DAYS;
    }

    public static function signingWindowDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_SIGNING_WINDOW_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('out_inspection_signing_window_days');

        return $value !== null ? (int) $value : self::DEFAULT_SIGNING_WINDOW_DAYS;
    }

    /**
     * §15.6 — 'other' is guaranteed present and last regardless of what an
     * agency has edited/saved, so §15.5's mandatory-reason rule always has
     * an escape valve. Never trusted to agency-edited JSON alone.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function refusalReasonPresetsFor(?int $agencyId): array
    {
        $presets = null;
        if ($agencyId) {
            $presets = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('refusal_reason_presets');
            $presets = is_string($presets) ? json_decode($presets, true) : $presets;
        }

        $presets = is_array($presets) && $presets !== [] ? $presets : self::DEFAULT_REFUSAL_REASON_PRESETS;

        $withoutOther = array_values(array_filter($presets, fn ($p) => ($p['key'] ?? null) !== 'other'));
        $withoutOther[] = ['key' => 'other', 'label' => 'Other'];

        return $withoutOther;
    }
}

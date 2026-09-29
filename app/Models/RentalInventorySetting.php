<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/rental-inventory.md §8 — one row per agency. Currently just the
 * move-out disposition vocabulary; deliberately its own model/table, not an
 * extension of RentalInspectionSetting (that model's condition_states work
 * is on a different, unlanded branch at the time this was built — see the
 * migration's own docblock for why touching it now was avoided). Same
 * BelongsToAgency + withoutGlobalScopes()-on-read convention as
 * RentalInspectionSetting, for the same reason: a settings lookup must
 * resolve for whichever agency_id is asked about, not whichever agency the
 * calling request happens to be scoped to.
 */
class RentalInventorySetting extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'disposition_presets',
        'baseline_disposition_key',
        'condition_states',
        'auto_send_report_enabled',
    ];

    protected $casts = [
        'disposition_presets' => 'array',
        'condition_states' => 'array',
        'auto_send_report_enabled' => 'boolean',
    ];

    /**
     * Sensible, neutral, multi-agency-safe default — no HFC-specific
     * wording, matching every other agency-configurable preset list in
     * this codebase (e.g. RentalInspectionSetting::refusalReasonPresetsFor()'s
     * own fallback). `requires_notes` on damaged/missing: quantity alone
     * doesn't explain WHAT happened; present/short are self-evident from
     * the quantity comparison itself. Labels match Johan's own wording from
     * §14's approved comparison mockup verbatim ("all there / short /
     * damaged / missing") — keys are unchanged from before that mockup, so
     * this is a label-only realignment, not a breaking change to any
     * already-stored disposition row or agency customization.
     */
    public const DEFAULT_DISPOSITION_PRESETS = [
        ['key' => 'present', 'label' => 'All there', 'requires_notes' => false],
        ['key' => 'short', 'label' => 'Short', 'requires_notes' => false],
        ['key' => 'damaged', 'label' => 'Damaged', 'requires_notes' => true],
        ['key' => 'missing', 'label' => 'Missing', 'requires_notes' => true],
    ];

    public static function dispositionPresetsFor(?int $agencyId): array
    {
        $presets = null;
        if ($agencyId) {
            $presets = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('disposition_presets');
            $presets = is_string($presets) ? json_decode($presets, true) : $presets;
        }

        return is_array($presets) && $presets !== [] ? $presets : self::DEFAULT_DISPOSITION_PRESETS;
    }

    public static function requiresNotesFor(?int $agencyId, string $dispositionKey): bool
    {
        $preset = collect(self::dispositionPresetsFor($agencyId))->firstWhere('key', $dispositionKey);

        return (bool) ($preset['requires_notes'] ?? false);
    }

    /**
     * §14 — "unchanged lines collapse to one grey line" needs to know
     * which of the agency's OWN disposition presets means "nothing wrong,"
     * the same problem RentalInspectionSetting::baselineConditionKeyFor()
     * already solves for condition states — mirrored here, not
     * reinvented. Never hardcoded to the literal key 'present': an agency
     * that renamed or reordered its own preset list still gets a sane
     * resolution.
     */
    public const DEFAULT_BASELINE_DISPOSITION_KEY = 'present';

    public static function baselineDispositionKeyFor(?int $agencyId): string
    {
        $presets = self::dispositionPresetsFor($agencyId);

        $saved = null;
        if ($agencyId) {
            $saved = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('baseline_disposition_key');
        }
        if (is_string($saved) && $saved !== '' && collect($presets)->contains('key', $saved)) {
            return $saved;
        }

        if (collect($presets)->contains('key', self::DEFAULT_BASELINE_DISPOSITION_KEY)) {
            return self::DEFAULT_BASELINE_DISPOSITION_KEY;
        }

        $noReasonNeeded = collect($presets)->first(fn ($p) => empty($p['requires_notes']));

        return $noReasonNeeded['key'] ?? ($presets[0]['key'] ?? self::DEFAULT_BASELINE_DISPOSITION_KEY);
    }

    /**
     * §13 — the CAPTURE-time condition chip vocabulary, a distinct concept
     * from `disposition_presets` above (which grades the move-in/move-out
     * DELTA, not the item's own state when first recorded). Sensible,
     * neutral, multi-agency-safe default matching the shape
     * RentalInspectionSetting::DEFAULT_CONDITION_STATES already
     * established for the same idea on a different document.
     */
    public const DEFAULT_CONDITION_STATES = [
        ['key' => 'new', 'label' => 'New', 'requires_notes' => false],
        ['key' => 'good', 'label' => 'Good', 'requires_notes' => false],
        ['key' => 'fair', 'label' => 'Fair', 'requires_notes' => false],
        ['key' => 'damaged', 'label' => 'Damaged', 'requires_notes' => true],
    ];

    public static function conditionStatesFor(?int $agencyId): array
    {
        $states = null;
        if ($agencyId) {
            $states = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('condition_states');
            $states = is_string($states) ? json_decode($states, true) : $states;
        }

        return is_array($states) && $states !== [] ? $states : self::DEFAULT_CONDITION_STATES;
    }

    /**
     * §41-follow-up (Job 3, 2026-09-28) — the public share link's expiry,
     * unlike Inspections' own agency-configurable version, is a fixed
     * constant here: nobody asked for it to be configurable, and adding a
     * setting nobody can reach from the Setup Wizard is worse than a plain
     * constant (non-negotiable #10a). Referenced directly by
     * RentalInventory::generatePublicLink().
     */
    public const DEFAULT_PUBLIC_LINK_EXPIRY_DAYS = 90;

    /** §41-follow-up (Job 3), Johan's ruling — auto-send on/off is an agency setting, default ON. Same read-time-default pattern as RentalInspectionSetting::autoSendReportEnabledFor(). */
    public const DEFAULT_AUTO_SEND_REPORT_ENABLED = true;

    public static function autoSendReportEnabledFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_AUTO_SEND_REPORT_ENABLED;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('auto_send_report_enabled');

        return $value !== null ? (bool) $value : self::DEFAULT_AUTO_SEND_REPORT_ENABLED;
    }
}

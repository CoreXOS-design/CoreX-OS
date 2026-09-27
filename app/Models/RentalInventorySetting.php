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
        'condition_states',
    ];

    protected $casts = [
        'disposition_presets' => 'array',
        'condition_states' => 'array',
    ];

    /**
     * Sensible, neutral, multi-agency-safe default — no HFC-specific
     * wording, matching every other agency-configurable preset list in
     * this codebase (e.g. RentalInspectionSetting::refusalReasonPresetsFor()'s
     * own fallback). `requires_notes` on damaged/missing: quantity alone
     * doesn't explain WHAT happened; present/short are self-evident from
     * the quantity comparison itself.
     */
    public const DEFAULT_DISPOSITION_PRESETS = [
        ['key' => 'present', 'label' => 'Present', 'requires_notes' => false],
        ['key' => 'short', 'label' => 'Short — quantity missing', 'requires_notes' => false],
        ['key' => 'damaged', 'label' => 'Damaged', 'requires_notes' => true],
        ['key' => 'missing', 'label' => 'Missing entirely', 'requires_notes' => true],
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
}

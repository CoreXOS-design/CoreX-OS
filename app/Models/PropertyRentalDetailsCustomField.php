<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-property-tab.md §2, Part 1 — an agency-defined field on a
 * property's Rental Details tab that CoreX doesn't ship (Lets Assist for
 * HFC, meaningless for another agency). Deliberately mirrors
 * RentalApplicationCustomField's shape (same static-helper names, same
 * key-generation scheme, same archive-not-delete lifecycle) as a PARALLEL
 * table, not a shared one — see the spec's §2 for why sharing was rejected.
 *
 * `key` is system-generated (self::generateKey()), namespaced `custom_`
 * (underscore, not a dot — same Laravel dot-path collision reasoning as the
 * sibling model) so it can never collide with a real `properties` column,
 * and immutable after creation — it's the JSON key every captured value is
 * stored under (properties.rental_details_custom_field_values, Part 2), so
 * renaming it would orphan already-captured values.
 *
 * `shown` is separate from `deleted_at` (retirement) — an agency can hide a
 * field from the tab without losing it or its already-captured values.
 * `advertise` (§4.1) is separate again — whether a SHOWN field's value
 * contributes a line to the generated advert block; a field can be shown on
 * the tab but never advertised, or advertised only once an agent ticks it
 * per-property intent aside (the per-field tick here is the DEFINITION's
 * own advertise-eligibility; §4.0's property-level master tick is what
 * actually turns the block on for a given property).
 */
class PropertyRentalDetailsCustomField extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_YES_NO = 'yes_no';
    public const TYPE_CURRENCY = 'currency';

    /**
     * Deliberately only the four types Johan asked for (free text / yes-no
     * / quantity / value) — .ai/specs/rental-property-tab.md §2.1. The
     * underlying mechanism (a plain string column, not a MySQL enum) could
     * support RentalApplicationCustomField's TYPE_DATE/TYPE_CHOICE_LIST/
     * TYPE_FILE too with no migration, the same way this feature could gain
     * a fifth type later with none either — none of those three are
     * offered on this feature's definition form because none were asked
     * for (spec §9).
     */
    public const FIELD_TYPES = [
        self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_YES_NO, self::TYPE_CURRENCY,
    ];

    protected $fillable = [
        'agency_id', 'key', 'label', 'help_text', 'field_type', 'options',
        'required', 'shown', 'advertise', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
        'shown' => 'boolean',
        'advertise' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * `custom_` + a slugified label, deduplicated against every key this
     * agency has EVER used (including retired ones) — same reasoning as
     * RentalApplicationCustomField::generateKey(): a retired field's
     * already-captured values must never be silently inherited by an
     * unrelated new field sharing the same generated key.
     */
    public static function generateKey(int $agencyId, string $label): string
    {
        $base = 'custom_' . \Illuminate\Support\Str::slug($label, '_');
        $key = $base;
        $suffix = 2;
        $existingKeys = static::withTrashed()->where('agency_id', $agencyId)->pluck('key')->all();
        while (in_array($key, $existingKeys, true)) {
            $key = $base . '_' . $suffix;
            $suffix++;
        }

        return $key;
    }

    /** Active, shown, ordered — what the property Rental tab actually renders. */
    public static function activeFor(int $agencyId): \Illuminate\Support\Collection
    {
        return static::where('agency_id', $agencyId)
            ->where('shown', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Every custom field for this agency, INCLUDING retired — for the
     * settings screen's own list (which shows a retired section), and for
     * resolving an already-captured value's label on a historical view
     * regardless of the definition's current state.
     */
    public static function allFor(int $agencyId): \Illuminate\Support\Collection
    {
        return static::withTrashed()
            ->where('agency_id', $agencyId)
            ->with('creator')
            ->orderBy('sort_order')
            ->get();
    }
}

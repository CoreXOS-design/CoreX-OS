<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-application-field-config.md §7 — an agency-defined field
 * CoreX doesn't ship. Full CRUD, agency-owned, archive-not-delete — same
 * shape as RentalApplicationHighlighter (the established pattern in this
 * module for a small, agency-owned, reorderable, archive-not-delete list).
 *
 * `key` is system-generated (RentalApplicationCustomField::generateKey()),
 * namespaced `custom_` (underscore, not a dot — see generateKey()'s own
 * docblock) so it can never collide with a real shipped column name, and
 * immutable after creation — it's the JSON key every captured
 * answer is stored under (rental_applications.custom_field_values), so a
 * rename would orphan already-captured answers.
 *
 * `shown` is separate from `deleted_at` (retirement) — an agency can hide a
 * custom field from new applications without losing it or its already-
 * captured answers, exactly parallel to the shipped-field
 * hidden_field_keys mechanism. Retiring (soft-deleting) the DEFINITION
 * never touches an application that already answered it — that answer
 * lives in the application's own custom_field_values JSON, untouched by
 * this row's own lifecycle.
 */
class RentalApplicationCustomField extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_YES_NO = 'yes_no';
    public const TYPE_CHOICE_LIST = 'choice_list';

    /**
     * File upload is deliberately NOT here yet — Johan, 2026-09-20: "file
     * upload last, reusing the existing document pipeline... if it starts
     * sprawling, stop and tell me." A plain string column (not a MySQL
     * enum — see the create migration's own docblock) means adding it
     * later never needs a schema migration just to widen an enum.
     */
    public const FIELD_TYPES = [
        self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_YES_NO, self::TYPE_CHOICE_LIST,
    ];

    protected $fillable = [
        'agency_id', 'key', 'label', 'help_text', 'field_type', 'options',
        'required', 'shown', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
        'shown' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * `custom_` + a slugified label, deduplicated against every key this
     * agency has EVER used (including retired ones — a retired
     * "custom_pet_deposit" and a brand new field from the same label must
     * never collide, since the retired one's already-captured answers are
     * still keyed under it).
     *
     * Underscore, not a dot — piece (c)(2) (capture) found the real
     * reason: Laravel treats a literal `.` as an array-nesting separator
     * EVERYWHERE a dot-path string is used (validation rule keys like
     * 'custom_field_values.' . $key, old(), $errors->has()) — a key
     * containing its own dot silently breaks all three, since
     * 'custom_field_values.custom.pet_details' resolves as THREE nested
     * levels, not two, even though the underlying array only has two.
     * Confirmed live: a submitted custom field's value showed as
     * permanently empty and "required" no matter what was typed. No real
     * custom field existed anywhere before this fix landed, so there was
     * nothing to migrate.
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

    /** Active, shown, ordered — what the applicant form/capture side actually renders. */
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
     * resolving an already-captured answer's label on a historical screen
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

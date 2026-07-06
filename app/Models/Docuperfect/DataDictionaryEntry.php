<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use App\Support\Docuperfect\DataDictionary\DataType;
use App\Support\Docuperfect\DataDictionary\ValidationResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-177 / WS0 — a versioned, typed CoreX real-estate Data Dictionary entry (spec §2.1).
 *
 * The heart of field binding: validation lives HERE (via {@see DataType}), so the same
 * rule fires at compile, fill, and sign. Entries are versioned so a compiled template can
 * pin the dictionary version it bound against — a later change never silently alters a
 * published template's meaning.
 *
 * agency_id NULL = CoreX-standard entry (shipped seed). A row with agency_id set OVERRIDES
 * that key for the agency. This model does NOT use BelongsToAgency (that scope would hide
 * the NULL-agency standard entries); resolution is explicit in {@see resolve()}.
 */
class DataDictionaryEntry extends Model
{
    use SoftDeletes;

    protected $table = 'data_dictionary_entries';

    protected $fillable = [
        'agency_id',
        'key',
        'version',
        'category',
        'label',
        'data_type',
        'validation',
        'format',
        'default_source',
        'description',
        'is_active',
        'superseded_by_id',
    ];

    protected $casts = [
        'validation' => 'array',
        'format' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Agency::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function dataType(): DataType
    {
        return DataType::from($this->data_type);
    }

    /** Validate a value against this entry's typed rule (+ its validation overrides). */
    public function validateValue(?string $value): ValidationResult
    {
        return $this->dataType()->validate($value, $this->validation ?? []);
    }

    public function scopeStandard(Builder $query): Builder
    {
        return $query->whereNull('agency_id');
    }

    /**
     * Point-in-time resolution of a dictionary key (spec §2.1):
     *   - prefer an AGENCY OVERRIDE (agency_id = $agencyId), else the CoreX-standard entry;
     *   - within that scope, the highest version ≤ $atVersion (or latest if $atVersion null);
     *   - only active entries.
     *
     * This is why pinning a dictionary version freezes a template's field meaning: resolving
     * at the pinned version always returns the entry as it was at compile time.
     */
    public static function resolve(string $key, ?int $agencyId = null, ?int $atVersion = null): ?self
    {
        $pick = static function (?int $scopeAgencyId) use ($key, $atVersion): ?self {
            $q = static::query()
                ->where('key', $key)
                ->where('is_active', true)
                ->when(
                    $scopeAgencyId === null,
                    fn (Builder $b) => $b->whereNull('agency_id'),
                    fn (Builder $b) => $b->where('agency_id', $scopeAgencyId),
                )
                ->when($atVersion !== null, fn (Builder $b) => $b->where('version', '<=', $atVersion))
                ->orderByDesc('version');

            return $q->first();
        };

        if ($agencyId !== null) {
            $override = $pick($agencyId);
            if ($override !== null) {
                return $override;
            }
        }

        return $pick(null);
    }

    /** The latest dictionary release version (max across all entries); 1 if empty. */
    public static function currentVersion(): int
    {
        return (int) (static::query()->max('version') ?? 1);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pastel-style enhancement, 2026-10-05 — the catalogue item "type" (labour/
 * part) made agency-configurable: add/rename/reorder/archive, same shape as
 * RentalVatType/RentalApplicationHighlighter. Every type still carries a
 * `kind` (labour|part) so the pre-existing labour-hours/parts-used reporting
 * (RentalReportService::jobCards()) keeps working regardless of what an
 * agency names or adds to this list — `kind` is the fixed classification,
 * `name` is the agency's own free label on top of it.
 */
class RentalCatalogueItemType extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const KIND_LABOUR = 'labour';
    public const KIND_PART = 'part';
    public const KINDS = [self::KIND_LABOUR, self::KIND_PART];

    protected $fillable = [
        'agency_id',
        'name',
        'kind',
        'is_active',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The two starting types every agency ships with — Johan's exact
     * existing values (Labour/Part), now just the seeded defaults on an
     * agency-editable list rather than a fixed enum. Idempotent — no-ops if
     * this agency already has any row (including soft-deleted).
     */
    public static function seedDefaultsFor(int $agencyId): void
    {
        if (static::withTrashed()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        $now = now();
        static::insert([
            [
                'agency_id' => $agencyId, 'name' => 'Labour', 'kind' => self::KIND_LABOUR,
                'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'agency_id' => $agencyId, 'name' => 'Part', 'kind' => self::KIND_PART,
                'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    /** The first active type of this kind for the agency — used to resolve a job-card line's kind back to a type when none is picked directly. */
    public static function firstOfKind(int $agencyId, string $kind): ?self
    {
        return static::where('agency_id', $agencyId)->where('kind', $kind)->active()->orderBy('sort_order')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function archive(): void
    {
        $this->update(['is_active' => false]);
        $this->delete();
    }

    public function restoreRecord(): void
    {
        $this->update(['is_active' => true]);
        $this->restore();
    }
}

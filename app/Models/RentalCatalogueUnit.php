<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pastel-style enhancement, 2026-10-05 — a catalogue item's "unit" (each,
 * hour, metre, ...) made an agency-configurable list instead of free text,
 * same shape as RentalCatalogueItemType. A job card line reads qty + unit
 * together ("1 dozen screws" vs "1 screw"), so the unit a catalogue item
 * carries drives how its lines read, not just a label on the item itself.
 */
class RentalCatalogueUnit extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'name',
        'is_active',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Sensible starting units — Johan's own list. Idempotent — no-ops if this agency already has any row (including soft-deleted). */
    public const DEFAULTS = ['Each', 'Dozen', 'Box', 'Pack', 'Metre', 'm²', 'Litre', 'kg', 'Hour', 'Day', 'Call-out'];

    public static function seedDefaultsFor(int $agencyId): void
    {
        if (static::withTrashed()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        $now = now();
        $rows = [];
        foreach (self::DEFAULTS as $i => $name) {
            $rows[] = [
                'agency_id' => $agencyId, 'name' => $name, 'is_active' => true,
                'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        static::insert($rows);
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

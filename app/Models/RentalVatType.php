<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agency-maintained VAT types, Pastel-style — Standard (follows the
 * agency's own `vat_rate`, live), No VAT (fixed 0%), Custom (the agent
 * types a rate on the line itself). Seeded per agency via seedDefaultsFor()
 * on AgencyCreated; an agency may add further fixed-rate types, rename, or
 * archive any of them — never a hard delete.
 */
class RentalVatType extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const RATE_MODE_AGENCY_RATE = 'agency_rate';
    public const RATE_MODE_FIXED = 'fixed';
    public const RATE_MODE_CUSTOM_PER_LINE = 'custom_per_line';

    protected $fillable = [
        'agency_id',
        'name',
        'rate_mode',
        'fixed_rate',
        'is_default',
        'is_active',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'fixed_rate' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Seeds the three starting types for a brand-new agency — same
     * idempotent AgencyCreated-reaction pattern as
     * RentalApplicationHighlighter::seedDefaultsFor() etc. No-ops if this
     * agency already has any VAT type row (including soft-deleted ones).
     */
    public static function seedDefaultsFor(int $agencyId): void
    {
        if (static::withTrashed()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        $now = now();
        static::insert([
            [
                'agency_id' => $agencyId, 'name' => 'Standard VAT', 'rate_mode' => self::RATE_MODE_AGENCY_RATE,
                'fixed_rate' => null, 'is_default' => true, 'is_active' => true, 'sort_order' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'agency_id' => $agencyId, 'name' => 'No VAT', 'rate_mode' => self::RATE_MODE_FIXED,
                'fixed_rate' => 0, 'is_default' => false, 'is_active' => true, 'sort_order' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'agency_id' => $agencyId, 'name' => 'Custom', 'rate_mode' => self::RATE_MODE_CUSTOM_PER_LINE,
                'fixed_rate' => null, 'is_default' => false, 'is_active' => true, 'sort_order' => 3,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    /** The resolved rate (%) this type carries right now — null for custom_per_line (the line supplies it). */
    public function liveRate(): ?float
    {
        return match ($this->rate_mode) {
            self::RATE_MODE_AGENCY_RATE => (float) PerformanceSetting::get('vat_rate', 15, $this->agency_id),
            self::RATE_MODE_FIXED => (float) $this->fixed_rate,
            default => null,
        };
    }

    public static function defaultFor(int $agencyId): ?self
    {
        return static::where('agency_id', $agencyId)->where('is_default', true)->first()
            ?? static::where('agency_id', $agencyId)->active()->orderBy('sort_order')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 2026-10-05 — Johan: the job-card line's VAT picker must show just the
     * type name ("Standard", "None", "Custom"), never the word "VAT" or a
     * rate, so it fits a narrow column. Strips a bare "VAT" word from
     * whatever the agency's own (editable) name currently is, rather than
     * hardcoding the three seeded strings — "Standard VAT" -> "Standard",
     * "No VAT" -> "No" -> "None" (the one seeded name this strip alone
     * doesn't land on), "Custom" unchanged (no "VAT" substring to strip).
     */
    public function shortLabel(): string
    {
        $label = trim((string) preg_replace('/\s+/', ' ', preg_replace('/\bVAT\b/i', '', $this->name)));

        return match (true) {
            $label === '' => 'Standard',
            $label === 'No' => 'None',
            default => $label,
        };
    }

    /** Exactly one default at a time — unsets every sibling before setting this one. */
    public function makeDefault(): void
    {
        static::where('agency_id', $this->agency_id)->where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    public function archive(): void
    {
        $this->delete();
    }

    public function restoreRecord(): void
    {
        $this->restore();
    }
}

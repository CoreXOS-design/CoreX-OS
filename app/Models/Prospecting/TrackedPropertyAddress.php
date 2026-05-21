<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-tracked-property address with history.
 *
 * Solves the "P24 publishes wrong address forever" problem — every source's
 * contribution lands as a row here with its `source_type` + `confidence`.
 * Exactly one row per TP has `is_primary = true` (enforced by the observer
 * in app/Observers/TrackedPropertyAddressObserver.php). The primary row's
 * address fields are denormalised onto `tracked_properties` as a cache.
 *
 * Promotion rule (per spec §3.2.1):
 *   - source_type IN ('manual_agent','manual_admin') → confidence=verified;
 *     demote current primary, set new row as primary.
 *   - otherwise → insert as history with appropriate confidence; do NOT
 *     demote the current primary.
 *
 * Spec: .ai/specs/mic-complete-spec.md §3.2.1.
 */
final class TrackedPropertyAddress extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'tracked_property_addresses';

    public const SOURCE_P24            = 'p24';
    public const SOURCE_PP             = 'pp';
    public const SOURCE_CHROME_CAPTURE = 'chrome_capture';
    public const SOURCE_CMAINFO        = 'cmainfo';
    public const SOURCE_MANUAL_AGENT   = 'manual_agent';
    public const SOURCE_MANUAL_ADMIN   = 'manual_admin';
    public const SOURCE_DEEDS_OFFICE   = 'deeds_office';

    public const CONFIDENCE_LOW      = 'low';
    public const CONFIDENCE_MEDIUM   = 'medium';
    public const CONFIDENCE_HIGH     = 'high';
    public const CONFIDENCE_VERIFIED = 'verified';

    protected $fillable = [
        'agency_id', 'tracked_property_id',
        'street_number', 'street_name', 'unit_number', 'complex_name',
        'suburb', 'suburb_normalised', 'town', 'province', 'postal_code',
        'latitude', 'longitude',
        'source_type', 'source_ref',
        'confidence', 'is_primary',
        'verified_by_user_id', 'verified_at',
        'notes',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'is_primary'    => 'boolean',
        'verified_at'   => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'latitude'      => 'decimal:7',
        'longitude'     => 'decimal:7',
    ];

    protected static function booted(): void
    {
        static::creating(function (TrackedPropertyAddress $row) {
            if (!empty($row->suburb) && empty($row->suburb_normalised)) {
                $row->suburb_normalised = self::normaliseSuburb($row->suburb);
            }
            if (!empty($row->street_name)) {
                $row->street_name = self::normaliseStreet($row->street_name);
            }
            if (empty($row->first_seen_at)) {
                $row->first_seen_at = now();
            }
            if (empty($row->last_seen_at)) {
                $row->last_seen_at = now();
            }
        });

        static::updating(function (TrackedPropertyAddress $row) {
            if ($row->isDirty('suburb')) {
                $row->suburb_normalised = self::normaliseSuburb($row->suburb);
            }
            if ($row->isDirty('street_name') && !empty($row->street_name)) {
                $row->street_name = self::normaliseStreet($row->street_name);
            }
        });
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Alias for the Phase C3 Edit Address UI — short name reads better in
     * Blade. Same FK target as verifiedBy().
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Human-friendly one-line address for display. Returns null when the
     * row carries no usable address fields (suburb-only with no street).
     *
     * Phase C3 — used by the TP detail page Address section.
     */
    public function getFormattedAddressAttribute(): ?string
    {
        $parts = [];
        if (!empty($this->unit_number))   $parts[] = "Unit {$this->unit_number}";
        if (!empty($this->complex_name))  $parts[] = $this->complex_name;
        if (!empty($this->street_number) && !empty($this->street_name)) {
            $parts[] = "{$this->street_number} {$this->street_name}";
        } elseif (!empty($this->street_name)) {
            $parts[] = $this->street_name;
        }
        if (!empty($this->suburb)) $parts[] = $this->suburb;
        return count($parts) > 0 ? implode(', ', $parts) : null;
    }

    public function scopeVerified(Builder $q): Builder
    {
        return $q->where('confidence', self::CONFIDENCE_VERIFIED);
    }

    public function scopePrimary(Builder $q): Builder
    {
        return $q->where('is_primary', true);
    }

    public function scopeBySource(Builder $q, string $type): Builder
    {
        return $q->where('source_type', $type);
    }

    /**
     * Canonical suburb normalisation — mirrors TrackedProperty::normaliseSuburb()
     * so addresses written here key-match TPs and external_refs on lookup.
     */
    public static function normaliseSuburb(?string $s): ?string
    {
        if ($s === null || $s === '') return null;
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\w\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', (string) $s);
        return trim((string) $s) ?: null;
    }

    /**
     * Canonical street normalisation — mirrors
     * TrackedPropertyMatchOrCreateService::normaliseStreetName() so "Mitchell
     * St" and "MITCHELL STREET" land in the same bucket.
     */
    public static function normaliseStreet(?string $name): ?string
    {
        if ($name === null || $name === '') return null;
        $name = trim($name);
        $name = preg_replace('/\bst\.?\b/i', 'Street', $name);
        $name = preg_replace('/\brd\.?\b/i', 'Road', $name);
        $name = preg_replace('/\bave\.?\b/i', 'Avenue', $name);
        $name = preg_replace('/\bdr\.?\b/i', 'Drive', $name);
        $name = preg_replace('/\blane\.?\b/i', 'Lane', $name);
        $name = preg_replace('/\bcl(?:o)?se?\.?\b/i', 'Close', $name);
        return ucwords(mb_strtolower((string) $name));
    }

    /**
     * Subset of address fields that get mirrored to the parent TP cache.
     * Used by TrackedPropertyAddressObserver. Single source of truth so
     * the observer and downstream readers stay in sync.
     *
     * @return array<int, string>
     */
    public static function cachedFields(): array
    {
        return [
            'street_number', 'street_name', 'unit_number', 'complex_name',
            'suburb', 'suburb_normalised', 'town', 'province', 'postal_code',
            'latitude', 'longitude',
        ];
    }

    /**
     * Default confidence for an ingestion source, per spec §7.1. Suburb-only
     * addresses (no street_name) downgrade to 'low' regardless of source —
     * the same rule the Phase C1 backfill applies inline.
     *
     * Recognised sources: p24, pp, chrome_capture, cmainfo, manual_agent,
     * manual_admin, deeds_office. Unknown sources fall through to 'low'.
     */
    public static function confidenceForSource(string $sourceType, ?string $streetName): string
    {
        if (empty($streetName)) {
            return self::CONFIDENCE_LOW;
        }
        return match ($sourceType) {
            self::SOURCE_MANUAL_AGENT, self::SOURCE_MANUAL_ADMIN => self::CONFIDENCE_VERIFIED,
            self::SOURCE_DEEDS_OFFICE, self::SOURCE_CMAINFO      => self::CONFIDENCE_HIGH,
            self::SOURCE_CHROME_CAPTURE, self::SOURCE_PP         => self::CONFIDENCE_MEDIUM,
            self::SOURCE_P24                                     => self::CONFIDENCE_LOW,
            default                                              => self::CONFIDENCE_LOW,
        };
    }
}

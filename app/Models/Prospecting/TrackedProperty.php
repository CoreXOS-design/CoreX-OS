<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Tracked Property — the universe of properties CoreX has intelligence on,
 * regardless of whether HFC has a mandate.
 *
 * Promoted-to-Stock = a TrackedProperty whose mandate has been won and is now
 * also represented in the `properties` table. The TP record stays as the audit
 * trail (source_chain preserved); the Property is the operational record.
 *
 * Every ingress (CMA presentations, P24 alerts, PP feed, Chrome capture, manual
 * entry, deeds-office lookups) MUST route through TrackedPropertyMatchOrCreateService.
 * See CLAUDE.md Universal Match-or-Create Rule.
 */
final class TrackedProperty extends Model
{
    use SoftDeletes, BelongsToAgency;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_PROMOTED = 'promoted';

    protected $fillable = [
        'agency_id', 'external_id',
        'street_number', 'street_name', 'unit_number', 'complex_name',
        'suburb', 'suburb_normalised', 'town', 'province', 'postal_code',
        'latitude', 'longitude', 'cma_gps_lat', 'cma_gps_lng',
        'erf_number', 'title_deed_number', 'cadastral_extent',
        'municipal_valuation', 'municipal_valuation_year',
        'last_known_asking_price', 'last_known_sold_price', 'last_known_sold_date',
        'property_type', 'bedrooms', 'bathrooms', 'garages',
        'floor_size_m2', 'erf_size_m2',
        'promoted_to_property_id', 'promoted_at', 'promoted_by_user_id',
        'source_chain', 'first_seen_at', 'last_enriched_at', 'last_enrichment_source',
        'status', 'duplicate_of_tracked_property_id',
    ];

    protected $casts = [
        'latitude'                 => 'decimal:7',
        'longitude'                => 'decimal:7',
        'cma_gps_lat'              => 'decimal:7',
        'cma_gps_lng'              => 'decimal:7',
        'municipal_valuation'      => 'decimal:2',
        'municipal_valuation_year' => 'integer',
        'last_known_asking_price'  => 'decimal:2',
        'last_known_sold_price'    => 'decimal:2',
        'last_known_sold_date'     => 'date',
        'bedrooms'                 => 'integer',
        'bathrooms'                => 'integer',
        'garages'                  => 'integer',
        'floor_size_m2'            => 'decimal:2',
        'erf_size_m2'              => 'decimal:2',
        'promoted_at'              => 'datetime',
        'promoted_by_user_id'      => 'integer',
        'source_chain'             => 'array',
        'first_seen_at'            => 'datetime',
        'last_enriched_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TrackedProperty $tp) {
            if (empty($tp->external_id)) {
                $tp->external_id = (string) Str::uuid();
            }
            if (empty($tp->first_seen_at)) {
                $tp->first_seen_at = now();
            }
            if (!empty($tp->suburb) && empty($tp->suburb_normalised)) {
                $tp->suburb_normalised = static::normaliseSuburb($tp->suburb);
            }
        });

        static::updating(function (TrackedProperty $tp) {
            if ($tp->isDirty('suburb')) {
                $tp->suburb_normalised = static::normaliseSuburb($tp->suburb);
            }
        });
    }

    /**
     * Canonical suburb normalisation: lowercase + trim + strip punctuation + collapse spaces.
     * Used by both the model on save AND the match-or-create service when looking up.
     */
    public static function normaliseSuburb(?string $s): ?string
    {
        if ($s === null || $s === '') return null;
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\w\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', (string) $s);
        return trim((string) $s) ?: null;
    }

    public function externalRefs(): HasMany
    {
        return $this->hasMany(TrackedPropertyExternalRef::class);
    }

    public function promotedProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'promoted_to_property_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by_user_id');
    }

    public function isPromoted(): bool
    {
        return $this->promoted_to_property_id !== null;
    }

    public function displayAddress(): string
    {
        $parts = array_filter([
            trim(($this->street_number ?? '') . ' ' . ($this->street_name ?? '')),
            $this->suburb,
        ]);
        return implode(', ', $parts) ?: '(no address)';
    }
}

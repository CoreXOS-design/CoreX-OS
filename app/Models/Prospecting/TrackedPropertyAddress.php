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
     * Canonical suburb normalisation — the ONE implementation. TrackedProperty::
     * normaliseSuburb() delegates here (2026-08-22, matcher-accuracy fix) instead
     * of duplicating the logic — "one matching brain", not two copies that can
     * silently drift apart.
     *
     * Apostrophes are DELETED, not turned into a space-separating token, unlike
     * every other punctuation mark. Bug found 2026-08-21 (real KZN South Coast
     * data): "Shaka's Rock" used to normalise to "shaka s rock" (three tokens)
     * while "Shakas Rock" normalised to "shakas rock" (two) — genuinely the same
     * place, treated as different suburbs by every strategy that keys on
     * suburb_normalised equality. Deleting the apostrophe first collapses both
     * spellings (and "St Michael's-on-Sea" / "St Michaels On Sea") onto the same
     * string, since the hyphen still becomes a space exactly as before.
     */
    public static function normaliseSuburb(?string $s): ?string
    {
        if ($s === null || $s === '') return null;
        $s = mb_strtolower(trim($s));
        $s = str_replace(["'", '’', '`'], '', $s);
        $s = preg_replace('/[^\w\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', (string) $s);
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }

        return self::canonicaliseSuburbAlias($s);
    }

    /**
     * Township vs. marketing/informal suburb name (Johan's known open fault:
     * "Three Hills" vs "Leisure Bay" — real KZN South Coast data, same general
     * area, two completely different strings by plain normalisation). There is
     * no schema column today to hold "these two names mean the same place", so
     * this resolves a normalised suburb string to a CANONICAL one via a small,
     * evidence-seeded alias table (config/property-suburb-aliases.php) — never
     * invented wholesale, only entries confirmed against real data. A suburb
     * with no known alias returns unchanged. Extend the config as more aliases
     * are confirmed; this function never needs to change.
     */
    public static function canonicaliseSuburbAlias(string $normalisedSuburb): string
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = [];
            foreach ((array) config('property-suburb-aliases.groups', []) as $group) {
                if (empty($group)) {
                    continue;
                }
                $canonical = $group[0];
                foreach ($group as $alias) {
                    $lookup[$alias] = $canonical;
                }
            }
        }

        return $lookup[$normalisedSuburb] ?? $normalisedSuburb;
    }

    /**
     * Canonical street normalisation — the ONE implementation.
     * TrackedPropertyMatchOrCreateService's private normaliseStreetName()
     * delegates here (2026-08-22) instead of duplicating the logic.
     *
     * Fixes applied 2026-08-22 (matcher-accuracy build, real data evidence):
     *
     *   - Street-type abbreviations are anchored to the END of the string
     *     (`$` instead of a bare word boundary). SA street names always put the
     *     type LAST ("123 Beach Road", never "Road Beach") — anchoring fixes a
     *     real bug where "St Michaels Manor" (a place name starting with
     *     "St" = Saint) was blindly expanded to "Street Michaels Manor",
     *     confirmed live in tracked_properties #38106-38112. "Mitchell St" (St
     *     at the end) still correctly becomes "Mitchell Street".
     *   - Crescent/Cres/Cr added — previously unhandled at all ("Riviera
     *     Crescent" != "Riviera Cres", real data risk).
     *   - Ordinals ("1st"/"First" etc.) are normalised to the numeral form —
     *     previously unhandled ("2nd Avenue" != "Second Avenue"; both forms
     *     exist in real tracked_properties data).
     *   - A leading "Unit N,"/"Flat N,"/"Door N," fragment that landed in the
     *     free-text street name (rather than the dedicated unit_number field)
     *     is stripped before comparison.
     *   - Internal whitespace is now collapsed (previously only leading/
     *     trailing trim ran) and general punctuation (trailing commas/periods
     *     beyond the abbreviation dots) is stripped, matching the suburb
     *     normaliser's thoroughness. Apostrophes are deleted, not spaced.
     */
    public static function normaliseStreet(?string $name): ?string
    {
        if ($name === null || $name === '') return null;
        $name = trim($name);

        // Strip a leading unit/complex fragment that landed in free-text
        // street_name instead of the dedicated unit_number/complex_name fields.
        $name = preg_replace('/^(?:unit|flat|door|apartment|apt|no)\s*\.?\s*[a-z0-9]+\s*,\s*/i', '', $name);

        $name = str_replace(["'", '’', '`'], '', $name);

        // Ordinals → numeral form, so "First Avenue" and "1st Avenue" agree.
        static $ordinals = [
            'first' => '1st', 'second' => '2nd', 'third' => '3rd', 'fourth' => '4th',
            'fifth' => '5th', 'sixth' => '6th', 'seventh' => '7th', 'eighth' => '8th',
            'ninth' => '9th', 'tenth' => '10th', 'eleventh' => '11th', 'twelfth' => '12th',
        ];
        foreach ($ordinals as $word => $numeral) {
            $name = preg_replace('/\b' . $word . '\b/i', $numeral, $name);
        }

        // Street-type abbreviations — anchored to end-of-string (see doc above).
        $name = preg_replace('/\bst\.?$/i', 'Street', $name);
        $name = preg_replace('/\brd\.?$/i', 'Road', $name);
        $name = preg_replace('/\bave\.?$/i', 'Avenue', $name);
        $name = preg_replace('/\bdr\.?$/i', 'Drive', $name);
        $name = preg_replace('/\blane\.?$/i', 'Lane', $name);
        $name = preg_replace('/\bcl(?:o)?se?\.?$/i', 'Close', $name);
        $name = preg_replace('/\bcr(?:es(?:cent)?)?\.?$/i', 'Crescent', $name);
        $name = preg_replace('/\bblvd\.?$/i', 'Boulevard', $name);

        // General punctuation cleanup + whitespace collapse (previously only
        // trim() ran — "Beach Rd.," kept its trailing comma and any internal
        // multi-space survived verbatim).
        $name = preg_replace('/[^\w\s]/u', ' ', (string) $name);
        $name = preg_replace('/\s+/', ' ', (string) $name);
        $name = trim((string) $name);

        return $name === '' ? null : ucwords(mb_strtolower($name));
    }

    /**
     * Fuzzy street-name match for a typo that defeats the exact strategies —
     * real example: tracked_properties #695 "10 Abelia Crescent" vs #839
     * "10 Abelia Cresent" (missing letter), same street number, same suburb,
     * genuinely the same property. Deliberately narrow: only usable as a
     * POSSIBLE-tier corroborating signal (never CONFIDENT on its own), and
     * only applies when both normalised names are long enough that a small
     * edit distance is meaningfully "close" rather than coincidentally short.
     */
    public static function streetNamesAreCloseTypo(?string $a, ?string $b): bool
    {
        $a = self::normaliseStreet($a);
        $b = self::normaliseStreet($b);
        if ($a === null || $b === null || $a === $b) {
            return false;
        }
        if (mb_strlen($a) < 6 || mb_strlen($b) < 6) {
            return false; // too short for edit-distance to be meaningful, not a coincidence risk worth taking
        }

        return levenshtein($a, $b) <= 2;
    }

    /**
     * unit/stand/erf number comparison key (property 15698 incident, 2026-08-21):
     * a deed's section_number '2' failed to match a stored unit_number '02' because
     * the match strategies compared them as exact strings. Leading zeros are
     * stripped ONLY when the value is purely numeric — "01" and "1" become the same
     * key, but "01" and "G01" (a real, distinct sectional label) are NEVER touched
     * and stay genuinely different, and "000" normalises to "0", never to "" (never
     * silently becomes "no value"). Case-insensitive on the alnum path so "g01" and
     * "G01" still match. Shared by TrackedPropertyMatchOrCreateService::
     * resolvePropertyMatch() and PropertyDuplicateMatchEvidence::candidateCount() —
     * ONE normalisation, so they can never disagree about what "matches" means.
     */
    public static function normaliseNumericIdentifier($value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '') {
            return null;
        }

        return ctype_digit($v) ? (ltrim($v, '0') ?: '0') : mb_strtolower($v);
    }

    /**
     * Great-circle distance in metres. Shared (2026-08-22, matcher-accuracy
     * build) between TrackedPropertyMatchOrCreateService's GPS-proximity
     * strategy and PropertyDuplicateMatchEvidence's display-only GPS row —
     * previously two separate copies of the same formula.
     */
    public static function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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

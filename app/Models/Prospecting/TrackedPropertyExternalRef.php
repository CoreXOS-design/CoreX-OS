<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\HasCanonicalPortalRef;
use App\Support\Property\PortalRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per (agency, source_type, source_ref) pointing at the TrackedProperty
 * that source identifies. The audit-grade index of "what every external system
 * thinks this property is".
 *
 * Created/updated by TrackedPropertyMatchOrCreateService — never written directly.
 */
final class TrackedPropertyExternalRef extends Model
{
    use SoftDeletes, BelongsToAgency;
    use HasCanonicalPortalRef;

    /** source_type values whose source_ref is a portal advert number (everything else keeps a NULL canonical_ref). */
    public const ADVERT_SOURCE_TYPES = ['p24', 'pp', 'property24', 'private_property', 'chrome_capture'];

    protected $fillable = [
        'agency_id', 'tracked_property_id',
        'source_type', 'source_ref', 'source_payload',
        'first_seen_at', 'last_seen_at',
        'canonical_ref', 'match_confidence', 'match_method', 'matched_at',
    ];

    /** @return array<string, ?string> */
    public function canonicalRefValues(): array
    {
        return ['canonical_ref' => self::canonicalFor((string) $this->source_type, (string) $this->source_ref, $this->source_payload)];
    }

    /** One place that turns (source_type, source_ref, payload) into the canonical advert reference, or null. */
    public static function canonicalFor(string $sourceType, string $sourceRef, ?array $payload = null): ?string
    {
        if (! in_array($sourceType, self::ADVERT_SOURCE_TYPES, true)) {
            return null;
        }
        $hint = $sourceType === 'chrome_capture' ? ($payload['source_site'] ?? null) : $sourceType;

        return PortalRef::normalise($sourceRef, PortalRef::portal($hint));
    }

    protected $casts = [
        'source_payload' => 'array',
        'first_seen_at'  => 'datetime',
        'last_seen_at'   => 'datetime',
        'matched_at'     => 'datetime',
    ];

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class);
    }
}

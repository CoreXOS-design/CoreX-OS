<?php

declare(strict_types=1);

namespace App\Models\Geocoding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 3f A1 — cache row for an address resolution attempt.
 *
 * One row per normalised address. Success and failure both cached so the
 * waterfall never repeats work. Suburb-normalised + source columns are
 * carried for analytics.
 *
 * NOT scoped by agency — geocoding is shared infrastructure across all
 * tenants (the GPS of "4 Tucker Avenue, Uvongo" doesn't change per agency).
 */
final class GeocodingCache extends Model
{
    use SoftDeletes;

    protected $table = 'geocoding_cache';

    protected $fillable = [
        'address_normalised',
        'address_raw',
        'latitude',
        'longitude',
        'confidence',
        'google_location_type',
        'source',
        'source_ref',
        'resolved_address',
        'municipality_name',
        'suburb_normalised',
        'failure_reason',
        'last_attempted_at',
        // Phase 11a additions
        'hit_count',
        'last_hit_at',
        'expires_at',
    ];

    protected $casts = [
        'latitude'          => 'decimal:7',
        'longitude'         => 'decimal:7',
        'last_attempted_at' => 'datetime',
        // Phase 11a
        'hit_count'         => 'integer',
        'last_hit_at'       => 'datetime',
        'expires_at'        => 'datetime',
    ];
}

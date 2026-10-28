<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Extracted owner roll from CMA Info "Sectional Title Scheme Owners List"
 * reports. One row per (scheme, section, owner) tuple within an agency.
 *
 * Spec: Phase 3a build prompt §1.3.
 */
class SchemeOwner extends Model
{
    use SoftDeletes, \App\Models\Concerns\FlagsDemoRows;

    protected $table = 'scheme_owners';

    protected $fillable = [
        'agency_id',
        'market_report_id',
        'scheme_name',
        'scheme_ss_number',
        'section_number',
        'flat_number',
        'owner_name',
        'extent_m2',
        'property_type',
        'latitude',
        'longitude',
        'contact_id',
        'matched_at',
        'is_demo',
    ];

    protected $casts = [
        'extent_m2'  => 'integer',
        'latitude'   => 'decimal:7',
        'longitude'  => 'decimal:7',
        'matched_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(MarketReport::class, 'market_report_id');
    }
}

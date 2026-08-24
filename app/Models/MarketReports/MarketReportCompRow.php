<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per comparable / subject / active-listing / scheme-owner entry
 * extracted from a market report. Companion to MarketDataPoint (key/value
 * warehouse) — this table holds the full row context.
 *
 * Spec: Phase 3a build prompt §1.2.
 *
 * SECURITY — agency_id is NOT NULL and this table is NOT part of the
 * market_data_points shared-pool exception (mic-complete-spec §13.1/§13.2
 * scopes that exception explicitly and only to market_data_points). Uses
 * BelongsToAgency so any future Eloquent query is scoped by default;
 * existing raw DB::table('market_report_comp_rows') callers (MicSnapshotHydrator,
 * MarketCompRowsSoldAdapter, MarketCompRowsActiveAdapter, CmaCoverageService)
 * bypass this global scope and MUST filter agency_id explicitly themselves.
 */
class MarketReportCompRow extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'market_report_comp_rows';

    public const ROW_SUBJECT = 'subject';
    public const ROW_COMP    = 'comp';
    public const ROW_LISTING = 'listing';
    public const ROW_OWNER   = 'owner';

    protected $fillable = [
        'market_report_id',
        'agency_id',
        'row_index',
        'row_type',
        'scheme_name',
        'section_number',
        'flat_number',
        'ss_number',
        'ss_year',
        'address',
        'suburb_normalised',
        'property_type',
        'extent_m2',
        'sale_date',
        'sale_price',
        'estimated_value',
        'r_per_m2',
        'list_price',
        'days_on_market',
        'municipal_valuation',
        'municipal_valuation_year',
        'condition',
        'distance_to_subject_m',
        'latitude',
        'longitude',
        'raw_row_json',
        'is_demo',
    ];

    protected $casts = [
        'row_index'                => 'integer',
        'ss_year'                  => 'integer',
        'extent_m2'                => 'integer',
        'sale_date'                => 'date',
        'sale_price'               => 'integer',
        'estimated_value'          => 'integer',
        'r_per_m2'                 => 'integer',
        'list_price'               => 'integer',
        'days_on_market'           => 'integer',
        'municipal_valuation'      => 'integer',
        'municipal_valuation_year' => 'integer',
        'distance_to_subject_m'    => 'integer',
        'latitude'                 => 'decimal:7',
        'longitude'                => 'decimal:7',
        'raw_row_json'             => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(MarketReport::class, 'market_report_id');
    }
}

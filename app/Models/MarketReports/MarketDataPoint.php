<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use App\Models\Prospecting\TrackedProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * ═════════════════════════════════════════════════════════════════════════
 * SHARED POOL — NO BelongsToAgency.
 * ═════════════════════════════════════════════════════════════════════════
 *
 * Per spec §13.2: market_data_points is the cross-agency shared market data
 * pool. The `agency_id` column on each row exists for AUDIT ONLY (which
 * agency uploaded the source report). Default reads against this model DO
 * NOT filter by agency_id — every CoreX agency benefits from the aggregated
 * pool.
 *
 * If you find yourself reaching for `BelongsToAgency` here, STOP. Re-read
 * spec §13. The agency-scoped table is `market_reports` (the upload audit),
 * not `market_data_points` (the normalised data).
 *
 * Audit access for super-admin investigations: use the explicit
 * auditScopeForAgency($agencyId) scope.
 *
 * Metric-value triplet rule: exactly ONE of (metric_value_numeric,
 * metric_value_date, metric_value_string) must be non-null. Enforced in
 * booted() at save time. No DB CHECK (kept portable across MySQL versions).
 *
 * Spec: .ai/specs/mic-complete-spec.md §3.2.4, §13.2.
 * ═════════════════════════════════════════════════════════════════════════
 */
final class MarketDataPoint extends Model
{
    use SoftDeletes;

    protected $table = 'market_data_points';

    public const CONFIDENCE_LOW      = 'low';
    public const CONFIDENCE_MEDIUM   = 'medium';
    public const CONFIDENCE_HIGH     = 'high';
    public const CONFIDENCE_VERIFIED = 'verified';

    protected $fillable = [
        'agency_id', 'report_id', 'tracked_property_id',
        'suburb_normalised', 'town',
        'metric_key',
        'metric_value_numeric', 'metric_value_date', 'metric_value_string',
        'metric_date',
        'confidence',
        'source_type', 'source_ref',
        'is_superseded', 'superseded_by_id',
    ];

    protected $casts = [
        'metric_value_numeric' => 'decimal:2',
        'metric_value_date'    => 'date',
        'metric_date'          => 'date',
        'is_superseded'        => 'boolean',
    ];

    protected static function booted(): void
    {
        // Metric-value triplet validation — exactly one populated.
        static::saving(function (MarketDataPoint $row) {
            $populated = array_filter([
                'numeric' => $row->metric_value_numeric !== null,
                'date'    => $row->metric_value_date !== null,
                'string'  => $row->metric_value_string !== null && $row->metric_value_string !== '',
            ]);
            $count = count($populated);
            if ($count !== 1) {
                throw new InvalidArgumentException(
                    "MarketDataPoint metric value must populate exactly ONE of "
                    . "metric_value_numeric|metric_value_date|metric_value_string, got {$count} "
                    . '(' . implode(',', array_keys($populated)) . ')'
                );
            }
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(MarketReport::class, 'report_id');
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Explicit audit-only scope for super-admin investigations into which
     * agency contributed which data points. NOT used by the default query
     * path — every other read of this model spans the shared pool.
     */
    public function scopeAuditScopeForAgency(Builder $q, int $agencyId): Builder
    {
        return $q->where('agency_id', $agencyId);
    }

    /**
     * Filter to only currently-authoritative points (not superseded).
     */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('is_superseded', false);
    }
}

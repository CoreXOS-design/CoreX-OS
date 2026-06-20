<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AI spot-check diff between the deterministic parser and the AI re-extraction.
 *
 * No BelongsToAgency — discrepancies follow the data point's shared-pool
 * nature. Resolved (not archived) in the normal lifecycle via resolved=true.
 * SoftDeletes is present so a report re-parse can SUPERSEDE the previous run's
 * discrepancies (deleted_at) instead of hard-deleting them — non-negotiable #1
 * (no hard deletes); the rows stay recoverable for audit.
 *
 * Severity ≥ medium fires a super-admin notification (handled by a separate
 * listener in Phase B).
 *
 * Spec: .ai/specs/mic-complete-spec.md §3.2.5.
 */
final class MarketDataDiscrepancy extends Model
{
    use SoftDeletes;

    protected $table = 'market_data_discrepancies';

    public const TYPE_VALUE_MISMATCH   = 'value_mismatch';
    public const TYPE_DATE_MISMATCH    = 'date_mismatch';
    public const TYPE_ADDRESS_MISMATCH = 'address_mismatch';
    public const TYPE_MISSING          = 'missing';
    public const TYPE_EXTRA            = 'extra';

    public const SEVERITY_LOW    = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH   = 'high';

    protected $fillable = [
        'report_id', 'data_point_id',
        'parsed_value', 'audit_value',
        'discrepancy_type', 'severity',
        'resolved', 'resolved_by_user_id', 'resolved_at', 'resolution_notes',
    ];

    protected $casts = [
        'resolved'    => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(MarketReport::class, 'report_id');
    }

    public function dataPoint(): BelongsTo
    {
        return $this->belongsTo(MarketDataPoint::class, 'data_point_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}

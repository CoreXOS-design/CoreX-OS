<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Upload record for a market / CMA report file.
 *
 * Each row tracks the file + parser state + spot-check results. The parsed
 * normalised values live in `market_data_points` (shared pool). Per-agency
 * scoping applies here (which agency uploaded the file).
 *
 * Spec: .ai/specs/mic-complete-spec.md §3.2.2.
 */
final class MarketReport extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'market_reports';

    public const PARSE_PENDING       = 'pending';
    public const PARSE_PARSING       = 'parsing';
    public const PARSE_PARSED        = 'parsed';
    public const PARSE_FAILED        = 'failed';
    public const PARSE_MANUAL_REVIEW = 'manual_review';

    public const SPOT_PENDING = 'pending';
    public const SPOT_RUNNING = 'running';
    public const SPOT_PASSED  = 'passed';
    public const SPOT_FLAGGED = 'flagged';
    public const SPOT_MANUAL  = 'manual';

    protected $fillable = [
        'agency_id', 'uploaded_by_user_id', 'report_type_id',
        'file_path', 'file_name', 'file_hash',
        'source_suburb', 'source_town',
        'report_date',
        'parse_status', 'parse_started_at', 'parse_completed_at', 'parser_version',
        'raw_extracted_json', 'data_points_count',
        'spot_check_status', 'spot_check_results',
        'notes',
    ];

    protected $casts = [
        'report_date'         => 'date',
        'parse_started_at'    => 'datetime',
        'parse_completed_at'  => 'datetime',
        'raw_extracted_json'  => 'array',
        'spot_check_results'  => 'array',
        'data_points_count'   => 'integer',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function reportType(): BelongsTo
    {
        return $this->belongsTo(MarketReportType::class, 'report_type_id');
    }

    public function dataPoints(): HasMany
    {
        return $this->hasMany(MarketDataPoint::class, 'report_id');
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(MarketDataDiscrepancy::class, 'report_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('parse_status', self::PARSE_PENDING);
    }

    public function scopeParsed(Builder $q): Builder
    {
        return $q->where('parse_status', self::PARSE_PARSED);
    }

    public function scopeFlagged(Builder $q): Builder
    {
        return $q->where('spot_check_status', self::SPOT_FLAGGED);
    }
}

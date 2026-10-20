<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A publisher's report layout learned from a successful AI read: a fingerprint
 * (phrases that identify the layout) plus the field mapping that reads it
 * deterministically afterwards. Spec: .ai/specs/mic-complete-spec.md §8.9.
 *
 * GLOBAL on purpose — no BelongsToAgency. A layout is a publisher's format
 * (phrases and column positions, never figures or names), shared by every agency.
 * Visible and manageable by CoreX owner roles only. "Retire" = soft delete.
 */
class MarketReportLearnedLayout extends Model
{
    use SoftDeletes;

    protected $table = 'market_report_learned_layouts';

    public const STATUS_LEARNED  = 'learned';
    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'name', 'publisher', 'status', 'fingerprint', 'anchors', 'mapping', 'report_kind',
        'selftest_score', 'times_used', 'last_used_at',
        'learned_from_report_id', 'learned_from_agency_id',
        'approved_at', 'approved_by_user_id', 'archived_by_user_id',
    ];

    protected $casts = [
        'anchors'        => 'array',
        'mapping'        => 'array',
        'selftest_score' => 'float',
        'times_used'     => 'integer',
        'last_used_at'   => 'datetime',
        'approved_at'    => 'datetime',
    ];

    public function scopeUsable(Builder $q): Builder
    {
        return $q->whereNull('deleted_at');
    }
}

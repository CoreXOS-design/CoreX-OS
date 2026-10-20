<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One unknown report layout that the AI could not (or was not allowed to) read.
 * Spec: .ai/specs/mic-complete-spec.md §8.9 ("AI cost protection: caps and layout cases").
 *
 * While a case is OPEN, any later upload in the same layout — from any agency — is queued
 * under it with NO AI call: one layout problem means one AI attempt platform-wide. The case
 * closes (learned → resolved) when a CoreX owner adds/approves the layout.
 *
 * GLOBAL on purpose — no BelongsToAgency. `anchors` are fixed headings, never figures.
 */
class MarketReportLayoutCase extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN     = 'open';
    public const STATUS_LEARNED  = 'learned';
    public const STATUS_RESOLVED = 'resolved';

    protected $table = 'market_report_layout_cases';

    protected $fillable = [
        'fingerprint', 'anchors', 'status', 'first_report_id', 'first_agency_id', 'learned_layout_id',
        'ai_attempts', 'last_code', 'last_note', 'resolved_at', 'resolved_by_user_id',
    ];

    protected $casts = [
        'anchors'     => 'array',
        'ai_attempts' => 'integer',
        'resolved_at' => 'datetime',
    ];
}

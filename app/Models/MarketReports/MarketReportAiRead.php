<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use Illuminate\Database\Eloquent\Model;

/**
 * The AI's raw answer for one file, cached by (file_hash, prompt_version) so the
 * same file is never paid for twice. Spec: .ai/specs/mic-complete-spec.md §8.9.
 *
 * GLOBAL on purpose — no BelongsToAgency. It is the model's reading of the file's
 * own bytes; the same bytes uploaded by another agency produce the same answer.
 * It is never exposed through any agency-facing screen: each agency's own report,
 * comp rows and data points are written (agency-scoped) from it.
 */
class MarketReportAiRead extends Model
{
    protected $table = 'market_report_ai_reads';

    protected $fillable = [
        'file_hash', 'prompt_version', 'model', 'payload',
        'input_tokens', 'output_tokens', 'cost_zar', 'first_report_id',
    ];

    protected $casts = [
        'payload'       => 'array',
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'cost_zar'      => 'float',
    ];
}

<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lookup of supported report types (cma_info_market_analysis, lightstone_avm,
 * etc.). Seeded by Database\Seeders\MarketReportTypesSeeder.
 *
 * Lookup table — NO BelongsToAgency (rows are global), NO SoftDeletes
 * (admin-managed; rows are added/disabled, never deleted-then-recovered).
 *
 * Spec: .ai/specs/mic-complete-spec.md §3.2.3.
 */
final class MarketReportType extends Model
{
    protected $table = 'market_report_types';

    protected $fillable = [
        'key', 'display_name', 'parser_class',
        'expected_fields_json', 'auto_approve', 'sample_file_path',
    ];

    protected $casts = [
        'expected_fields_json' => 'array',
        'auto_approve'         => 'boolean',
    ];

    public function reports(): HasMany
    {
        return $this->hasMany(MarketReport::class, 'report_type_id');
    }
}

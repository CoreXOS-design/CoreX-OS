<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PresentationSnapshot extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'presentation_id',
        'generated_by_user_id',
        'created_by_user_id',
        'market_analytics_run_id',
        'sale_probability_run_id',
        'snapshot_json',
        'computed_json',
        'engine_versions_json',
        'inputs_json',
        'output_summary_json',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function marketAnalyticsRun()
    {
        return $this->belongsTo(MarketAnalyticsRun::class, 'market_analytics_run_id');
    }

    public function saleProbabilityRun()
    {
        return $this->belongsTo(SaleProbabilityRun::class, 'sale_probability_run_id');
    }

    public function getInputsArray(): array
    {
        return $this->inputs_json ? json_decode($this->inputs_json, true) ?? [] : [];
    }

    public function getOutputSummaryArray(): array
    {
        return $this->output_summary_json ? json_decode($this->output_summary_json, true) ?? [] : [];
    }
}

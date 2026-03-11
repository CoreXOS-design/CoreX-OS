<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleProbabilityRun extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'market_analytics_run_id',
        'market_analytics_model_version',
        'market_analytics_inputs_hash',
        'model_version',
        'inputs_hash',
        'inputs_json',
        'outputs_json',
        'breakdown_json',
        'data_sources_json',
        'created_by',
    ];

    protected $casts = [
        'inputs_json'      => 'array',
        'outputs_json'     => 'array',
        'breakdown_json'   => 'array',
        'data_sources_json' => 'array',
    ];

    public function marketAnalyticsRun()
    {
        return $this->belongsTo(MarketAnalyticsRun::class, 'market_analytics_run_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

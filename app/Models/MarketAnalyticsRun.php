<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class MarketAnalyticsRun extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
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

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PresentationVersion extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'presentation_id',
        'compiled_by',
        'blueprint_version',
        'analytics_run_id',
        'probability_run_id',
        'data_snapshot_json',
        'hydration_summary_json',
        'compiled_at',
        // Phase 3 — AI summary fields.
        'ai_variant_id',
        'ai_summary_text',
        'ai_summary_raw_text',
        'ai_summary_edited_by_agent',
        'ai_summary_generated_at',
        'ai_summary_edited_at',
        'ai_summary_model',
        'ai_summary_prompt_hash',
        'ai_summary_input_facts_json',
    ];

    protected $casts = [
        'compiled_at'                 => 'datetime',
        'hydration_summary_json'      => 'array',
        'ai_summary_edited_by_agent'  => 'boolean',
        'ai_summary_generated_at'     => 'datetime',
        'ai_summary_edited_at'        => 'datetime',
        'ai_summary_input_facts_json' => 'array',
    ];

    public function aiVariant()
    {
        return $this->belongsTo(PresentationAiVariant::class, 'ai_variant_id');
    }

    public function hasAiSummary(): bool
    {
        return !empty($this->ai_summary_text);
    }

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function compiledBy()
    {
        return $this->belongsTo(User::class, 'compiled_by');
    }

    public function getSnapshotArray(): array
    {
        return json_decode($this->data_snapshot_json, true) ?? [];
    }
}

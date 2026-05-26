<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PresentationArticle extends Model
{
    use BelongsToAgency, SoftDeletes;


    protected $fillable = [
        'agency_id',
        'presentation_id',
        'url',
        'snapshot_text',
        'content_hash',
        'fetched_at',
        'ai_summary_text',
        'ai_summary_model',
        'ai_summary_created_at',
        'tags_json',
    ];

    protected $casts = [
        'fetched_at'            => 'datetime',
        'ai_summary_created_at' => 'datetime',
        'tags_json'             => 'array',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }
}

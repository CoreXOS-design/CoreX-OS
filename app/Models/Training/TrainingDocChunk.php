<?php

namespace App\Models\Training;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingDocChunk extends Model
{
    protected $table = 'training_doc_chunks';

    protected $fillable = [
        'doc_id', 'chunk_index', 'heading_path', 'section_anchor',
        'content', 'word_count', 'embedding', 'has_embedding',
    ];

    protected $casts = [
        'chunk_index'   => 'integer',
        'word_count'    => 'integer',
        'embedding'     => 'array',
        'has_embedding' => 'boolean',
    ];

    public function doc(): BelongsTo
    {
        return $this->belongsTo(TrainingDoc::class, 'doc_id');
    }
}

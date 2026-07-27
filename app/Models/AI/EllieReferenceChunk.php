<?php

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EllieReferenceChunk extends Model
{
    use SoftDeletes;

    protected $table = 'ellie_reference_chunks';

    protected $fillable = [
        'source_id',
        'chunk_index',
        'content',
        'embedding',
        'has_embedding',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'embedding' => 'array',
        'has_embedding' => 'boolean',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(EllieReferenceSource::class, 'source_id');
    }
}

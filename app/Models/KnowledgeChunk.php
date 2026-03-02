<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    protected $table = 'knowledge_chunks';

    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'section_title',
        'page_number',
        'char_count',
        'word_count',
        'metadata',
        'embedding',
        'has_embedding',
    ];

    protected $casts = [
        'metadata' => 'array',
        'embedding' => 'array',
        'has_embedding' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    /**
     * Full-text search scope — uses PostgreSQL tsvector when available,
     * falls back to LIKE for SQLite/MySQL.
     */
    public function scopeSearch($query, string $searchQuery)
    {
        $query->whereHas('document', function ($q) {
            $q->where('is_active', true)
              ->where('status', 'ready')
              ->where('is_ellie_enabled', true);
        });

        if (config('database.default') === 'pgsql') {
            return $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$searchQuery])
                         ->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) DESC", [$searchQuery]);
        }

        // Fallback: LIKE search for SQLite/MySQL
        $term = '%' . $searchQuery . '%';
        return $query->where(function ($q) use ($term) {
            $q->where('content', 'LIKE', $term)
              ->orWhere('section_title', 'LIKE', $term);
        });
    }

    /**
     * Simple keyword search — LIKE on content and section_title.
     */
    public function scopeKeywordSearch($query, array $keywords)
    {
        $query->whereHas('document', function ($q) {
            $q->where('is_active', true)
              ->where('status', 'ready')
              ->where('is_ellie_enabled', true);
        });

        return $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $term = '%' . $keyword . '%';
                $q->orWhere('content', 'LIKE', $term)
                  ->orWhere('section_title', 'LIKE', $term);
            }
        });
    }
}

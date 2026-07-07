<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeDocument extends Model
{
    use SoftDeletes;


    protected $table = 'knowledge_documents';

    protected $fillable = [
        'category_id',
        'uploaded_by',
        'title',
        'description',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'chunk_count',
        'page_count',
        'status',
        'error_message',
        'is_active',
        'is_ellie_enabled',
        'version',
        'effective_date',
        'expiry_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_ellie_enabled' => 'boolean',
        'effective_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEllieEnabled($query)
    {
        return $query->where('is_ellie_enabled', true);
    }

    public function scopeInCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    public function getIsExpiredAttribute(): bool
    {
        if (!$this->expiry_date) {
            return false;
        }
        return $this->expiry_date->isPast();
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'ready' => '<span class="ds-badge ds-badge-success">Ready</span>',
            'processing' => '<span class="ds-badge ds-badge-warning">Processing</span>',
            'error' => '<span class="ds-badge ds-badge-danger">Error</span>',
            default => '<span class="ds-badge ds-badge-default">' . e(ucfirst($this->status)) . '</span>',
        };
    }
}

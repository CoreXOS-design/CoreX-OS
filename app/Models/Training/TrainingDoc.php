<?php

namespace App\Models\Training;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingDoc extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'training_docs';

    protected $fillable = [
        'slug', 'title', 'role_audience', 'file_path', 'content_hash',
        'word_count', 'reading_time_minutes', 'is_required', 'sort_order',
        'version', 'last_indexed_at', 'agency_id',
    ];

    protected $casts = [
        'is_required'          => 'boolean',
        'sort_order'           => 'integer',
        'version'              => 'integer',
        'word_count'           => 'integer',
        'reading_time_minutes' => 'integer',
        'last_indexed_at'      => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────

    public function chunks(): HasMany
    {
        return $this->hasMany(TrainingDocChunk::class, 'doc_id')->orderBy('chunk_index');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(TrainingDocRead::class, 'doc_id');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(TrainingDocBookmark::class, 'doc_id');
    }

    // ── Accessors ──────────────────────────────────────────

    public function getReadingTimeAttribute(): int
    {
        if ($this->reading_time_minutes > 0) {
            return $this->reading_time_minutes;
        }

        return max(1, (int) ceil($this->word_count / 250));
    }

    // ── Business Logic ─────────────────────────────────────

    /**
     * Get the completion percentage for a specific user.
     */
    public function getProgressForUser(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        $read = $this->reads()->where('user_id', $userId)->first();
        if (! $read) {
            return 0;
        }

        $completedSections = $read->sections_completed ?? [];
        $totalSections = $this->chunks()
            ->whereNotNull('section_anchor')
            ->distinct('section_anchor')
            ->count('section_anchor');

        if ($totalSections === 0) {
            return $read->completed_at ? 100 : 0;
        }

        return min(100, (int) round((count($completedSections) / $totalSections) * 100));
    }

    /**
     * Check if this doc is required for a given role.
     */
    public function isRequiredForRole(string $role): bool
    {
        if (! $this->is_required) {
            return false;
        }

        if ($this->role_audience === 'all') {
            return true;
        }

        return $this->role_audience === $role;
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeForRole($query, string $role)
    {
        return $query->where(function ($q) use ($role) {
            $q->where('role_audience', 'all')
              ->orWhere('role_audience', $role);
        });
    }

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }
}

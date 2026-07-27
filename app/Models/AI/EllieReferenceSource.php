<?php

namespace App\Models\AI;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An admin-approved external page Ellie may search.
 *
 * No agency_id — deliberately global, same category as the SA-legislation
 * knowledge base. Spec: .ai/specs/ellie-reference-sources.md §5.
 */
class EllieReferenceSource extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    protected $table = 'ellie_reference_sources';

    protected $fillable = [
        'url',
        'title',
        'added_by_user_id',
        'is_active',
        'last_fetched_at',
        'last_fetch_status',
        'fetch_error',
        'content_hash',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_fetched_at' => 'datetime',
    ];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(EllieReferenceChunk::class, 'source_id');
    }
}

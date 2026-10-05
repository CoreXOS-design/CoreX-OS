<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One page a user has pinned to the Favourites panel above their name in the
 * sidebar. Spec: .ai/specs/sidebar-favourites.md
 *
 * Deliberately NOT BelongsToAgency — see the migration's header for why.
 * Scoping is by user_id, always, everywhere.
 */
class UserNavFavourite extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Hard ceiling on how many pages one user may pin (spec §3, §8). */
    public const MAX_PER_USER = 25;

    protected $fillable = [
        'user_id',
        'nav_key',
        'label',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The stored key is 'p:<pathname>'. This is the pathname on its own.
     */
    public function path(): string
    {
        return str_starts_with($this->nav_key, 'p:')
            ? substr($this->nav_key, 2)
            : $this->nav_key;
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

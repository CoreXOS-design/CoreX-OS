<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A CoreX product release note shown to users as a pop-up.
 *
 * Spec: .ai/specs/system-updates.md
 *
 * DELIBERATELY NOT tenant-owned: no agency_id column, no BelongsToAgency trait, and
 * AgencyScope is never registered — see the migration docblock and spec §3 for the
 * full reasoning. Because the scope is never applied, no request-code path ever
 * needs withoutGlobalScope() to read this table.
 */
class SystemUpdate extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public const AUDIENCE_ALL    = 'all';
    public const AUDIENCE_ADMINS = 'admins';

    protected $fillable = [
        'title',
        'body',
        'type',
        'audience',
        'link_url',
        'link_label',
        'image_path',
        'status',
        'published_at',
        'notify_reset_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'published_at'    => 'datetime',
        'notify_reset_at' => 'datetime',
    ];

    /**
     * Keep the published-list cache honest (spec §9.6).
     *
     * Bound to model events rather than called from the controller so that NO
     * mutation path — publish, unpublish, edit, re-notify, archive, restore, a
     * tinker session, a future seeder — can forget to bust it. A stale cache here
     * means a user either misses an update or keeps seeing a withdrawn one, and
     * neither failure would raise an error anywhere.
     */
    protected static function booted(): void
    {
        $bust = static fn () => \App\Services\SystemUpdateService::bustCache();

        static::saved($bust);
        static::deleted($bust);
        static::restored($bust);
        static::forceDeleted($bust);
    }

    // ── Relations ───────────────────────────────────────────────────────────

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(SystemUpdateView::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /** Published and not future-dated. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    // ── State ───────────────────────────────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * The moment a dismissal must be at-or-after to still count.
     *
     * Re-notify (spec §7.4) moves this watermark forward instead of deleting view
     * rows, so an update can be re-shown without destroying the record of who saw
     * the original.
     */
    public function acknowledgementFloor(): ?\Illuminate\Support\Carbon
    {
        return $this->notify_reset_at ?? $this->published_at;
    }

    // ── Presentation ────────────────────────────────────────────────────────

    /**
     * Chip descriptor for this update's type.
     *
     * Absorbs an unknown/removed type with a neutral "Update" chip rather than
     * throwing on a missing config key (spec §9.4).
     */
    public function typeChip(): array
    {
        return config('system-updates.types.' . $this->type)
            ?? config('system-updates.unknown_type');
    }

    public function typeLabel(): string
    {
        return $this->typeChip()['label'] ?? 'Update';
    }

    public function audienceLabel(): string
    {
        return config('system-updates.audiences.' . $this->audience . '.label')
            ?? 'Everyone';
    }

    /** Button text — defaults when a URL was given but no label (spec §9.2). */
    public function linkLabelOrDefault(): string
    {
        return filled($this->link_label) ? $this->link_label : 'Take me there';
    }

    public function hasLink(): bool
    {
        return filled($this->link_url);
    }

    /** Author name, or "System" when the authoring account has been deleted (spec §9.4). */
    public function authorName(): string
    {
        return $this->author?->name ?: 'System';
    }
}

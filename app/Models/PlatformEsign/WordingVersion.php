<?php

namespace App\Models\PlatformEsign;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One version of a web document's wording + rates (spec §11.2, §11.14). A DRAFT (`is_published` = false) is editable;
 * a PUBLISHED version is immutable and never deleted — a sent document pins one and always renders it, so editing
 * wording = a new version, never an update. The only column a published row may still change is `layout_json`
 * (pagination is recalculated lazily when the layout engine's REV moves).
 */
class WordingVersion extends Model
{
    use SoftDeletes;

    protected $table = 'platform_esign_wording_versions';

    protected $fillable = [
        'template_id', 'parent_version_id', 'version', 'version_date', 'change_note', 'content_json', 'rates_json', 'layout_json',
        'is_published', 'rev', 'published_at', 'created_by', 'published_by',
    ];

    protected $casts = [
        'version_date' => 'date', 'content_json' => 'array', 'rates_json' => 'array', 'layout_json' => 'array',
        'is_published' => 'boolean', 'published_at' => 'datetime', 'rev' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            if (!$v->getOriginal('is_published')) {
                return;
            }
            $changed = array_keys($v->getDirty());
            $illegal = array_diff($changed, ['layout_json', 'updated_at']);
            if ($illegal) {
                throw new \LogicException('A published agreement version is immutable (' . implode(', ', $illegal) . '). Publish a new version instead.');
            }
        });
        static::deleting(function (self $v) {
            if ($v->is_published) {
                throw new \LogicException('A published agreement version can never be deleted.');
            }
        });
    }

    public function template() { return $this->belongsTo(Template::class, 'template_id')->withTrashed(); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function publisher() { return $this->belongsTo(User::class, 'published_by'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_version_id')->withTrashed(); }
    public function documents() { return $this->hasMany(Document::class, 'wording_version_id'); }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    public function scopeDrafts(Builder $q): Builder
    {
        return $q->where('is_published', false);
    }

    /** The newest published version of a template — what a NEW agreement pins and what /legal shows. */
    public function scopeCurrentFor(Builder $q, int $templateId): Builder
    {
        return $q->where('template_id', $templateId)->where('is_published', true)->orderByDesc('published_at')->orderByDesc('id');
    }

    public function isDraft(): bool
    {
        return !$this->is_published;
    }

    /** "Version 1.0 — 28 September 2026" (a draft shows its working title instead of a number). */
    public function label(): string
    {
        return 'Version ' . $this->version . ' — ' . $this->version_date->format('j F Y');
    }

    /** "1.1" for a published version, "Draft" while unpublished. */
    public function shortName(): string
    {
        return $this->is_published ? $this->version : 'Draft';
    }
}

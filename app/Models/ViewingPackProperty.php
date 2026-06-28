<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One property selected into a Viewing Pack. `sort_order` is the agent's manual
 * drag order (spec §4); `source` is core_match | ad_hoc (spec §3). Selection
 * mechanics arrive in Step 3 — this is the persistence shape.
 */
class ViewingPackProperty extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'viewing_pack_id',
        'property_id',
        'sort_order',
        'source',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public const SOURCE_CORE_MATCH = 'core_match';
    public const SOURCE_AD_HOC     = 'ad_hoc';
    public const SOURCES = [self::SOURCE_CORE_MATCH, self::SOURCE_AD_HOC];

    protected static function booted(): void
    {
        // Cascade soft-delete / restore to the documents under this property,
        // mirroring ViewingPack → property cascade.
        static::deleting(function (ViewingPackProperty $prop) {
            if ($prop->isForceDeleting()) {
                return;
            }
            foreach ($prop->viewingPackDocuments()->get() as $doc) {
                $doc->delete();
            }
        });

        static::restoring(function (ViewingPackProperty $prop) {
            foreach ($prop->viewingPackDocuments()->onlyTrashed()->get() as $doc) {
                $doc->restore();
            }
        });
    }

    /** Order by the agent's chosen drag sequence. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function viewingPack(): BelongsTo
    {
        return $this->belongsTo(ViewingPack::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function viewingPackDocuments(): HasMany
    {
        return $this->hasMany(ViewingPackDocument::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An owner-made section of the public Training Videos page.
 *
 * Spec: .ai/specs/training-videos.md §3.1
 *
 * Platform record, not tenant-scoped — CoreX's own content, same family as Webinar.
 * Archiving (soft delete) hides the group AND its videos from the public page; the
 * videos keep their group id, so a restore brings them all back (spec D10).
 */
class TrainingVideoGroup extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'created_by', 'updated_by'];

    public function videos(): HasMany
    {
        return $this->hasMany(TrainingVideo::class);
    }
}

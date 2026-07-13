<?php

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-239 region model (Johan-final): the per-agency ALIAS on an MDB municipality.
 * The municipality is canonical/immutable (from the MDB point-in-polygon layer);
 * `alias` is the agency-editable display name. `displayName()` = alias ?: municipality.
 */
class RegionAlias extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $table = 'region_aliases';

    protected $fillable = [
        'agency_id',
        'municipality',
        'alias',
        'alias_suggestion',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    /** What the agency sees everywhere: the alias, or the municipal name when no alias is set. */
    public function displayName(): string
    {
        $alias = trim((string) $this->alias);

        return $alias !== '' ? $alias : (string) $this->municipality;
    }
}

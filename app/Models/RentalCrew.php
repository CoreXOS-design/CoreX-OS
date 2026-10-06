<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 2026-10-05 — Johan's ruling: "Agents and staff are never maintenance
 * crew. Crew are people with NO CoreX access, set up by the agency admin,
 * pickable on job cards. A crew can be several people or just a named
 * team ('Team 1') — the admin decides." Full CRUD, agency-scoped,
 * soft-delete only. Archived crews still display on old job cards
 * (RentalJobCard::crew() is never scoped to active-only) but cannot be
 * newly picked (the Assign dropdown only ever lists active crews).
 */
class RentalCrew extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'name',
        'email',
        'phone',
        'notes',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(RentalCrewMember::class)->orderBy('name');
    }

    public function jobCards(): HasMany
    {
        return $this->hasMany(RentalJobCard::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function archive(): void
    {
        $this->delete();
    }

    public function restoreRecord(): void
    {
        $this->restore();
    }
}

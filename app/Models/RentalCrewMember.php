<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** 2026-10-05 — one named person on a RentalCrew. A crew with zero members is valid (a plain team). */
class RentalCrewMember extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_crew_id',
        'name',
        'phone',
        'role',
        'created_by_user_id',
    ];

    public function crew(): BelongsTo
    {
        return $this->belongsTo(RentalCrew::class, 'rental_crew_id');
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

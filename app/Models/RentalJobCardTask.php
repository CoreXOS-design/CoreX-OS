<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-442 — one row on a job card's task checklist. */
class RentalJobCardTask extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    /** Matches the DB column default — correct in-memory right after ::create(), not only on reload. */
    protected $attributes = [
        'is_done' => false,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
        'description',
        'is_done',
        'sort_order',
        'done_by_user_id',
        'done_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_done' => 'boolean',
        'sort_order' => 'integer',
        'done_at' => 'datetime',
    ];

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    public function doneByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_user_id');
    }
}

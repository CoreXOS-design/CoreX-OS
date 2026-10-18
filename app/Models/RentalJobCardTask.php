<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rebuilt 2026-10-05 — a numbered job on the card (Johan: "1 - Paint
 * lounge"), with its own parts & labour lines underneath it. Display
 * numbering is the task's position in RentalJobCard::tasks()' own
 * sort_order, not a stored column.
 */
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

    public function lines(): HasMany
    {
        return $this->hasMany(RentalJobCardLine::class, 'rental_job_card_task_id')->orderBy('sort_order')->orderBy('id');
    }

    /** §17.4.6 — the lines that count (accepted); lines() above is every live line, for the office's own "awaiting" block. */
    public function acceptedLines(): HasMany
    {
        return $this->lines()->accepted();
    }

    /** Selling subtotal of the ACCEPTED lines only (§17.4.6). Reads the loaded relation when present, else queries. */
    public function subtotal(): float
    {
        $lines = $this->relationLoaded('lines') ? $this->lines->where('office_status', RentalJobCardLine::OFFICE_ACCEPTED) : $this->acceptedLines()->get();

        return (float) $lines->sum('line_total');
    }
}

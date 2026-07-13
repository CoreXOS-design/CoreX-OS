<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-238 — one pending decision: "this filing row's address matched several properties;
 * which one is it?" A machine must not guess here — the wrong pick attaches a legal
 * filing to the wrong house.
 */
class FilingLinkReview extends Model
{
    use BelongsToAgency;

    protected $table = 'filing_link_review_queue';

    protected $fillable = [
        'agency_id',
        'filing_id',
        'matched_at',
        'match_status',
        'matched_address',
        'candidates_json',
        'chosen_property_id',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_note',
    ];

    protected $casts = [
        'candidates_json' => 'array',
        'matched_at'      => 'datetime',
        'reviewed_at'     => 'datetime',
    ];

    public function filing()
    {
        return $this->belongsTo(DocumentFiling::class, 'filing_id');
    }

    public function chosenProperty()
    {
        return $this->belongsTo(Property::class, 'chosen_property_id');
    }

    public function scopePending($query)
    {
        return $query->where('match_status', 'pending');
    }
}

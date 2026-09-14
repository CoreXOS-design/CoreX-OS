<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-Core-Matches, share-history piece — "the buyer opened the link" is a
 * separate event from "the agent shared the link" and must never be
 * conflated with one. Recorded from the public shared-match page
 * (SharedMatchController::show()/showViaBuyerLink()) — no auth, so every
 * hit counts; there is no session to distinguish an agent previewing their
 * own link from the buyer actually opening it.
 */
class ContactMatchLinkOpen extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'contact_match_id',
        'opened_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
    ];

    public static function record(ContactMatch $match): self
    {
        return static::create([
            'agency_id'        => $match->agency_id,
            'contact_match_id' => $match->id,
            'opened_at'        => now(),
        ]);
    }

    public function contactMatch(): BelongsTo
    {
        return $this->belongsTo(ContactMatch::class);
    }
}
